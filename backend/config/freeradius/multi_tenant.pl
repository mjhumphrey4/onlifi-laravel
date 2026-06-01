#!/usr/bin/perl
#
# OnLiFi Multi-Tenant FreeRADIUS Module
# 
# This Perl module enables FreeRADIUS to route authentication requests
# to the correct tenant database based on the NAS-Identifier attribute.
#
# KEY CONCEPT: MikroTik Identity as Router Identifier
# - Each MikroTik router's System Identity is used as NAS-Identifier
# - Dynamic/public routers share one RADIUS client secret in clients.conf
# - The module looks up the router by NAS-Identifier to find the tenant/site
# - Then queries the tenant's database for voucher authentication
#
# Installation:
# 1. Copy to /etc/freeradius/3.0/mods-config/perl/onlifi_multi_tenant.pl
# 2. Enable perl module: ln -s ../mods-available/perl /etc/freeradius/3.0/mods-enabled/
# 3. Configure perl module: set module = /etc/freeradius/3.0/mods-config/perl/onlifi_multi_tenant.pl
# 4. Add 'perl' to authorize, accounting, and post-auth sections
#
# MikroTik Configuration:
# /system identity set name="YOUR-UNIQUE-ROUTER-NAME"
# /radius add address=RADIUS_SERVER secret="GLOBAL_ONLIFI_RADIUS_SECRET" service=hotspot
#

use strict;
use warnings;
use DBI;
use Digest::MD5 qw(md5_hex);

# FreeRADIUS special variables - must be declared with 'our'
our (%RAD_REQUEST, %RAD_REPLY, %RAD_CHECK, %RAD_CONFIG);

# Database configuration - UPDATE THESE VALUES
my $central_db_host = $ENV{'RADIUS_DB_HOST'} // "localhost";
my $central_db_name = $ENV{'RADIUS_DB_NAME'} // "onlifi_central";
my $central_db_user = $ENV{'RADIUS_DB_USER'} // "onlifi";
my $central_db_pass = $ENV{'RADIUS_DB_PASS'} // "password";

# Cache for tenant/site database lookups (keyed by router_identifier)
my %tenant_cache;

# FreeRADIUS return codes
use constant {
    RLM_MODULE_REJECT   => 0,
    RLM_MODULE_FAIL     => 1,
    RLM_MODULE_OK       => 2,
    RLM_MODULE_HANDLED  => 3,
    RLM_MODULE_INVALID  => 4,
    RLM_MODULE_USERLOCK => 5,
    RLM_MODULE_NOTFOUND => 6,
    RLM_MODULE_NOOP     => 7,
    RLM_MODULE_UPDATED  => 8,
};

#
# Get tenant/site database info from router identifier (NAS-Identifier)
# Site-linked routers authenticate against the site's own database when present.
#
sub get_tenant_db {
    my ($router_identifier) = @_;
    
    # Check cache first
    return $tenant_cache{$router_identifier} if exists $tenant_cache{$router_identifier};
    
    my $dbh = DBI->connect(
        "DBI:mysql:database=$central_db_name;host=$central_db_host",
        $central_db_user,
        $central_db_pass,
        { RaiseError => 0, PrintError => 0 }
    );
    
    return undef unless $dbh;
    
    # Look up by router_identifier (unique per router). A site is an
    # independent operational database, so prefer sites.* DB credentials when
    # the NAS is linked to a site.
    my $sth = $dbh->prepare(q{
        SELECT
            COALESCE(NULLIF(s.database_name, ''), t.database_name) AS database_name,
            COALESCE(NULLIF(s.database_host, ''), t.database_host) AS database_host,
            COALESCE(s.database_port, t.database_port) AS database_port,
            COALESCE(NULLIF(s.database_username, ''), t.database_username) AS database_username,
            COALESCE(NULLIF(s.database_password, ''), t.database_password) AS database_password,
            n.site_id,
            n.tenant_id,
            t.database_name AS tenant_database_name,
            s.database_name AS site_database_name
        FROM nas n
        JOIN tenants t ON n.tenant_id = t.id
        LEFT JOIN sites s ON s.id = n.site_id AND s.tenant_id = n.tenant_id
        WHERE n.router_identifier = ?
        AND t.is_active = 1
        AND t.status = 'approved'
    });
    
    $sth->execute($router_identifier);
    my $row = $sth->fetchrow_hashref();
    $sth->finish();
    $dbh->disconnect();
    
    if ($row) {
        $tenant_cache{$router_identifier} = $row;
        return $row;
    }
    
    return undef;
}

sub acct_unique_id {
    my ($router_identifier, $username) = @_;

    return $RAD_REQUEST{'Acct-Unique-Session-Id'} if ($RAD_REQUEST{'Acct-Unique-Session-Id'} // '') ne '';

    return md5_hex(join('|',
        $router_identifier,
        $RAD_REQUEST{'Acct-Session-Id'} // '',
        $username,
        $RAD_REQUEST{'Calling-Station-Id'} // '',
        $RAD_REQUEST{'Framed-IP-Address'} // ''
    ));
}

sub normalize_mac {
    my ($mac) = @_;
    $mac //= '';
    $mac =~ s/[^A-Fa-f0-9]//g;
    return lc($mac);
}

sub start_voucher_timer {
    my ($dbh, $username) = @_;

    my $sth = $dbh->prepare(q{
        UPDATE vouchers SET
            status = CASE WHEN status = 'unused' THEN 'used' ELSE status END,
            first_used_at = COALESCE(first_used_at, NOW()),
            last_used_at = NOW(),
            expires_at = COALESCE(expires_at, DATE_ADD(NOW(), INTERVAL COALESCE(validity_minutes, validity_hours * 60) MINUTE)),
            used_by_mac = COALESCE(NULLIF(used_by_mac, ''), ?),
            used_by_ip = COALESCE(NULLIF(used_by_ip, ''), ?),
            last_accounting_at = NOW()
        WHERE voucher_code = ?
        AND status IN ('unused', 'used')
    });

    unless ($sth) {
        &radiusd::radlog(1, "PERL VOUCHER ERROR: Could not prepare timer update for $username: " . ($dbh->errstr // 'unknown error'));
        return 0;
    }

    my $rows = $sth->execute(
        $RAD_REQUEST{'Calling-Station-Id'} // '',
        $RAD_REQUEST{'Framed-IP-Address'} // '',
        $username
    );
    my $err = $dbh->errstr;
    $sth->finish();

    unless ($rows) {
        &radiusd::radlog(1, "PERL VOUCHER WARNING: Timer update affected no rows for $username: " . ($err // 'not found or expired'));
        return 0;
    }

    return 1;
}

sub expire_voucher {
    my ($dbh, $username, $reason) = @_;
    $reason //= 'time_limit';

    my $voucher_update = $dbh->prepare(q{
        UPDATE vouchers SET
            status = 'expired',
            expired_reason = ?,
            last_accounting_at = NOW()
        WHERE voucher_code = ?
    });
    $voucher_update->execute($reason, $username) if $voucher_update;
    $voucher_update->finish() if $voucher_update;

    my $radcheck_delete = $dbh->prepare(q{ DELETE FROM radcheck WHERE username = ? });
    $radcheck_delete->execute($username) if $radcheck_delete;
    $radcheck_delete->finish() if $radcheck_delete;

    my $radreply_delete = $dbh->prepare(q{ DELETE FROM radreply WHERE username = ? });
    $radreply_delete->execute($username) if $radreply_delete;
    $radreply_delete->finish() if $radreply_delete;
}

#
# Authorize - Check if user exists and get password
#
sub authorize {
    # Use NAS-Identifier (router_identifier) instead of IP since routers have dynamic IPs
    my $router_identifier = $RAD_REQUEST{'NAS-Identifier'} // '';
    my $username = $RAD_REQUEST{'User-Name'} // '';
    
    &radiusd::radlog(1, "PERL AUTHORIZE START: User=$username, NAS-ID=$router_identifier");

    if (exists $RAD_REQUEST{'User-Password'} && ($RAD_REQUEST{'User-Password'} // '') eq '') {
        &radiusd::radlog(1, "PERL WARNING: Empty User-Password received for $username. Check login.html PAP password sync.");
    }
    
    # Get tenant database using router identifier
    my $tenant = get_tenant_db($router_identifier);
    
    unless ($tenant) {
        &radiusd::radlog(1, "PERL ERROR: No tenant found for router identifier: $router_identifier");
        return RLM_MODULE_REJECT;
    }
    
    my $site_db = defined $tenant->{site_database_name} ? $tenant->{site_database_name} : 'NULL';
    &radiusd::radlog(1, "PERL: Found tenant/site DB: $tenant->{database_name} at $tenant->{database_host} (tenant_id=$tenant->{tenant_id}, site_id=" . ($tenant->{site_id} // 'NULL') . ", site_db=$site_db)");
    
    # Connect to tenant database
    my $dbh = DBI->connect(
        "DBI:mysql:database=$tenant->{database_name};host=$tenant->{database_host};port=" . ($tenant->{database_port} // 3306),
        $tenant->{database_username},
        $tenant->{database_password},
        { RaiseError => 0, PrintError => 0 }
    );
    
    unless ($dbh) {
        my $err = $DBI::errstr // 'unknown error';
        &radiusd::radlog(1, "PERL ERROR: Cannot connect to tenant database: $tenant->{database_name} - $err");
        return RLM_MODULE_FAIL;
    }
    
    &radiusd::radlog(1, "PERL: Connected to tenant database successfully");

    my $calling_mac = $RAD_REQUEST{'Calling-Station-Id'} // '';
    my $voucher_sth = $dbh->prepare(q{
        SELECT voucher_code, status, site_id, expires_at, used_by_mac,
               (expires_at IS NOT NULL AND expires_at <= NOW()) AS is_expired
        FROM vouchers
        WHERE voucher_code = ?
        LIMIT 1
    });

    unless ($voucher_sth) {
        &radiusd::radlog(1, "PERL ERROR: Could not prepare voucher lookup for $username: " . ($dbh->errstr // 'unknown error'));
        $dbh->disconnect();
        return RLM_MODULE_FAIL;
    }

    $voucher_sth->execute($username);
    my $voucher = $voucher_sth->fetchrow_hashref();
    $voucher_sth->finish();

    unless ($voucher) {
        &radiusd::radlog(1, "PERL ERROR: Voucher $username does not exist in selected tenant/site database");
        $dbh->disconnect();
        return RLM_MODULE_NOTFOUND;
    }

    if (defined $tenant->{site_id} && defined $voucher->{site_id} && $voucher->{site_id} ne $tenant->{site_id}) {
        &radiusd::radlog(1, "PERL REJECT: Voucher $username belongs to site_id=$voucher->{site_id}, expected site_id=$tenant->{site_id}");
        $dbh->disconnect();
        return RLM_MODULE_REJECT;
    }

    if (($voucher->{status} // '') eq 'expired' || ($voucher->{status} // '') eq 'disabled' || ($voucher->{is_expired} // 0)) {
        expire_voucher($dbh, $username, 'time_limit') if ($voucher->{is_expired} // 0);
        &radiusd::radlog(1, "PERL REJECT: Voucher $username is expired or disabled");
        $dbh->disconnect();
        return RLM_MODULE_REJECT;
    }

    if (($voucher->{used_by_mac} // '') ne '' && $calling_mac ne '' && normalize_mac($voucher->{used_by_mac}) ne normalize_mac($calling_mac)) {
        &radiusd::radlog(1, "PERL REJECT: Voucher $username is bound to MAC $voucher->{used_by_mac}; request came from $calling_mac");
        $dbh->disconnect();
        return RLM_MODULE_REJECT;
    }
    
    # Check radcheck for user. When a NAS is linked to a site, only vouchers
    # created for that same site are accepted by routers in that site.
    my $sth = $dbh->prepare(q{
        SELECT rc.attribute, rc.value, rc.op
        FROM radcheck rc
        LEFT JOIN vouchers v ON v.voucher_code = rc.username
        WHERE rc.username = ?
        AND (? IS NULL OR v.site_id = ?)
    });
    
    &radiusd::radlog(1, "PERL: Executing radcheck query for user: $username");
    
    $sth->execute($username, $tenant->{site_id}, $tenant->{site_id});
    
    my $found = 0;
    while (my $row = $sth->fetchrow_hashref()) {
        $found = 1;
        my $attr = $row->{attribute};
        my $val = $row->{value};
        
        # Set all check attributes to control list
        $RAD_CHECK{$attr} = $val;
        
        &radiusd::radlog(1, "PERL: Found radcheck: $attr = $val for user $username");
    }
    $sth->finish();
    
    unless ($found) {
        &radiusd::radlog(1, "PERL ERROR: User not found in radcheck: $username");
        eval {
            my $voucher_diag = $dbh->prepare(q{
                SELECT voucher_code, status, site_id, expires_at
                FROM vouchers
                WHERE voucher_code = ?
                LIMIT 1
            });
            $voucher_diag->execute($username);
            if (my $voucher = $voucher_diag->fetchrow_hashref()) {
                my $voucher_site = defined $voucher->{site_id} ? $voucher->{site_id} : 'NULL';
                my $expected_site = defined $tenant->{site_id} ? $tenant->{site_id} : 'NULL';
                my $expires_at = defined $voucher->{expires_at} ? $voucher->{expires_at} : 'NULL';
                &radiusd::radlog(1, "PERL DIAG: Voucher exists status=$voucher->{status}, voucher_site_id=$voucher_site, expected_site_id=$expected_site, expires_at=$expires_at; radcheck missing or site mismatch.");
            } else {
                &radiusd::radlog(1, "PERL DIAG: Voucher $username does not exist in the selected tenant/site database.");
            }
            $voucher_diag->finish();
        };
        $dbh->disconnect();
        return RLM_MODULE_NOTFOUND;
    }
    
    &radiusd::radlog(1, "PERL SUCCESS: User $username authorized from tenant DB: $tenant->{database_name}");
    
    # Get reply attributes from radreply
    $sth = $dbh->prepare(q{
        SELECT attribute, value, op
        FROM radreply
        WHERE username = ?
    });
    
    $sth->execute($username);
    
    while (my $row = $sth->fetchrow_hashref()) {
        $RAD_REPLY{$row->{attribute}} = $row->{value};
    }
    $sth->finish();
    $dbh->disconnect();
    
    return RLM_MODULE_OK;
}

#
# Authenticate - Verify password (handled by PAP module)
#
sub authenticate {
    return RLM_MODULE_OK;
}

#
# Accounting - Record session data
#
sub accounting {
    my $router_identifier = $RAD_REQUEST{'NAS-Identifier'} // '';
    my $nas_ip = $RAD_REQUEST{'NAS-IP-Address'} // '';
    my $username = $RAD_REQUEST{'User-Name'} // '';
    my $acct_status = $RAD_REQUEST{'Acct-Status-Type'} // '';
    my $unique_id = acct_unique_id($router_identifier, $username);
    
    &radiusd::radlog(1, "PERL ACCOUNTING: User=$username, Status=$acct_status, NAS-ID=$router_identifier, Unique-ID=$unique_id");
    
    my $tenant = get_tenant_db($router_identifier);
    unless ($tenant) {
        &radiusd::radlog(1, "PERL ACCOUNTING ERROR: No tenant found for $router_identifier");
        return RLM_MODULE_NOOP;
    }
    
    my $dbh = DBI->connect(
        "DBI:mysql:database=$tenant->{database_name};host=$tenant->{database_host};port=" . ($tenant->{database_port} // 3306),
        $tenant->{database_username},
        $tenant->{database_password},
        { RaiseError => 0, PrintError => 0 }
    );
    
    unless ($dbh) {
        &radiusd::radlog(1, "PERL ACCOUNTING ERROR: Cannot connect to tenant DB");
        return RLM_MODULE_FAIL;
    }
    
    if ($acct_status eq 'Start') {
        my $sth = $dbh->prepare(q{
            INSERT INTO radacct 
            (acctsessionid, acctuniqueid, username, nasipaddress, nasportid, nasporttype,
             acctstarttime, acctstoptime, acctsessiontime, acctauthentic, connectinfo_start,
             acctinputoctets, acctoutputoctets, calledstationid, callingstationid, 
             acctterminatecause, servicetype, framedprotocol, framedipaddress, acctupdatetime)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL, 0, ?, ?, 0, 0, ?, ?, '', ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                acctupdatetime = NOW(),
                acctstoptime = NULL,
                acctterminatecause = '',
                framedipaddress = VALUES(framedipaddress),
                callingstationid = VALUES(callingstationid)
        });

        if ($sth) {
            my $rows = $sth->execute(
                $RAD_REQUEST{'Acct-Session-Id'} // '',
                $unique_id,
                $username,
                $nas_ip,
                $RAD_REQUEST{'NAS-Port-Id'} // ($RAD_REQUEST{'NAS-Port'} // ''),
                $RAD_REQUEST{'NAS-Port-Type'} // 'Wireless-802.11',
                $RAD_REQUEST{'Acct-Authentic'} // 'RADIUS',
                $RAD_REQUEST{'Connect-Info'} // '',
                $RAD_REQUEST{'Called-Station-Id'} // '',
                $RAD_REQUEST{'Calling-Station-Id'} // '',
                $RAD_REQUEST{'Service-Type'} // 'Login-User',
                $RAD_REQUEST{'Framed-Protocol'} // 'PPP',
                $RAD_REQUEST{'Framed-IP-Address'} // ''
            );

            if ($rows) {
                &radiusd::radlog(1, "PERL ACCOUNTING: Session started for $username (Session-ID: " . ($RAD_REQUEST{'Acct-Session-Id'} // 'N/A') . ")");
            } else {
                &radiusd::radlog(1, "PERL ACCOUNTING ERROR: Failed to insert Start record for $username: " . $dbh->errstr);
            }
            $sth->finish();
        } else {
            &radiusd::radlog(1, "PERL ACCOUNTING ERROR: Could not prepare Start record for $username: " . ($dbh->errstr // 'unknown error'));
        }

        if (start_voucher_timer($dbh, $username)) {
            &radiusd::radlog(1, "PERL ACCOUNTING: Voucher $username marked used and timer started");
        } else {
            &radiusd::radlog(1, "PERL ACCOUNTING WARNING: Voucher $username was not updated on Start");
        }
    }
    elsif ($acct_status eq 'Stop') {
        my $session_time = $RAD_REQUEST{'Acct-Session-Time'} // 0;
        my $input_octets = $RAD_REQUEST{'Acct-Input-Octets'} // 0;
        my $output_octets = $RAD_REQUEST{'Acct-Output-Octets'} // 0;
        my $terminate_cause = $RAD_REQUEST{'Acct-Terminate-Cause'} // 'Unknown';
        
        my $sth = $dbh->prepare(q{
            UPDATE radacct SET
                acctstoptime = NOW(),
                acctsessiontime = ?,
                acctinputoctets = ?,
                acctoutputoctets = ?,
                acctterminatecause = ?,
                connectinfo_stop = ?,
                acctupdatetime = NOW()
            WHERE acctuniqueid = ?
        });

        if ($sth) {
            my $rows = $sth->execute(
                $session_time,
                $input_octets,
                $output_octets,
                $terminate_cause,
                $RAD_REQUEST{'Connect-Info'} // '',
                $unique_id
            );

            if ($rows) {
                &radiusd::radlog(1, "PERL ACCOUNTING: Session stopped for $username - Duration: ${session_time}s, Download: ${input_octets} bytes, Upload: ${output_octets} bytes, Cause: $terminate_cause");
            } else {
                &radiusd::radlog(1, "PERL ACCOUNTING WARNING: No matching session found for Stop packet (Unique-ID: $unique_id)");
            }
            $sth->finish();
        } else {
            &radiusd::radlog(1, "PERL ACCOUNTING ERROR: Could not prepare Stop record for $username: " . ($dbh->errstr // 'unknown error'));
        }
        
        # Stop updates usage totals but does not invalidate an unexpired voucher.
        # The voucher remains reusable until the wall-clock expiry from first login.
        my $total_octets = $input_octets + $output_octets;
        my $voucher_update = $dbh->prepare(q{
            UPDATE vouchers SET
                status = CASE
                    WHEN expires_at IS NOT NULL AND expires_at <= NOW() THEN 'expired'
                    WHEN data_limit_mb IS NOT NULL AND ROUND(? / 1048576, 2) >= data_limit_mb THEN 'expired'
                    ELSE 'used'
                END,
                last_used_at = NOW(),
                total_session_time_minutes = CEIL(? / 60),
                total_data_used_mb = ROUND(? / 1048576, 2),
                last_accounting_at = NOW(),
                expired_reason = CASE
                    WHEN expires_at IS NOT NULL AND expires_at <= NOW() THEN 'time_limit'
                    WHEN data_limit_mb IS NOT NULL AND ROUND(? / 1048576, 2) >= data_limit_mb THEN 'data_limit'
                    ELSE NULL
                END
            WHERE voucher_code = ?
            AND status IN ('unused', 'used')
        });
        if ($voucher_update) {
            $voucher_update->execute($total_octets, $session_time, $total_octets, $total_octets, $username);
            $voucher_update->finish();
        } else {
            &radiusd::radlog(1, "PERL ACCOUNTING ERROR: Could not prepare voucher Stop update for $username: " . ($dbh->errstr // 'unknown error'));
        }

        my $voucher_status_sth = $dbh->prepare(q{
            SELECT status FROM vouchers WHERE voucher_code = ? LIMIT 1
        });
        my $voucher_status = '';
        if ($voucher_status_sth) {
            $voucher_status_sth->execute($username);
            ($voucher_status) = $voucher_status_sth->fetchrow_array();
            $voucher_status_sth->finish();
        }
        
        if (($voucher_status // '') eq 'expired') {
            expire_voucher($dbh, $username, 'time_limit');
            &radiusd::radlog(1, "PERL ACCOUNTING: Voucher $username expired and was removed from RADIUS");
        } else {
            &radiusd::radlog(1, "PERL ACCOUNTING: Voucher $username usage updated; still active until expiry");
        }
    }
    elsif ($acct_status eq 'Interim-Update') {
        my $session_time = $RAD_REQUEST{'Acct-Session-Time'} // 0;
        my $input_octets = $RAD_REQUEST{'Acct-Input-Octets'} // 0;
        my $output_octets = $RAD_REQUEST{'Acct-Output-Octets'} // 0;
        
        my $sth = $dbh->prepare(q{
            UPDATE radacct SET
                acctupdatetime = NOW(),
                acctsessiontime = ?,
                acctinputoctets = ?,
                acctoutputoctets = ?
            WHERE acctuniqueid = ?
        });

        if ($sth) {
            my $rows = $sth->execute(
                $session_time,
                $input_octets,
                $output_octets,
                $unique_id
            );

            if ($rows) {
                &radiusd::radlog(3, "PERL ACCOUNTING: Interim update for $username - Duration: ${session_time}s, Download: ${input_octets} bytes, Upload: ${output_octets} bytes");
            } else {
                &radiusd::radlog(1, "PERL ACCOUNTING WARNING: No matching session found for Interim-Update (Unique-ID: $unique_id)");
            }
            $sth->finish();
        } else {
            &radiusd::radlog(1, "PERL ACCOUNTING ERROR: Could not prepare Interim update for $username: " . ($dbh->errstr // 'unknown error'));
        }

        my $total_octets = $input_octets + $output_octets;
        my $voucher_interim = $dbh->prepare(q{
            UPDATE vouchers SET
                last_used_at = NOW(),
                total_session_time_minutes = CEIL(? / 60),
                total_data_used_mb = ROUND(? / 1048576, 2),
                last_accounting_at = NOW()
            WHERE voucher_code = ?
            AND status = 'used'
        });
        if ($voucher_interim) {
            $voucher_interim->execute($session_time, $total_octets, $username);
            $voucher_interim->finish();
        } else {
            &radiusd::radlog(1, "PERL ACCOUNTING ERROR: Could not prepare voucher Interim update for $username: " . ($dbh->errstr // 'unknown error'));
        }
    }
    else {
        &radiusd::radlog(1, "PERL ACCOUNTING: Unknown Acct-Status-Type: $acct_status");
    }
    
    &radiusd::radlog(1, "PERL ACCOUNTING SUCCESS: Recorded $acct_status for user $username");
    
    $dbh->disconnect();
    return RLM_MODULE_OK;
}

#
# Post-auth - Log authentication result
#
sub post_auth {
    my $router_identifier = $RAD_REQUEST{'NAS-Identifier'} // '';
    my $username = $RAD_REQUEST{'User-Name'} // '';
    my $is_reject = (($RAD_CHECK{'Post-Auth-Type'} // '') eq 'Reject') || exists $RAD_REQUEST{'Module-Failure-Message'};
    
    my $tenant = get_tenant_db($router_identifier);
    return RLM_MODULE_NOOP unless $tenant;
    
    my $dbh = DBI->connect(
        "DBI:mysql:database=$tenant->{database_name};host=$tenant->{database_host};port=" . ($tenant->{database_port} // 3306),
        $tenant->{database_username},
        $tenant->{database_password},
        { RaiseError => 0, PrintError => 0 }
    );
    
    return RLM_MODULE_NOOP unless $dbh;
    
    my $sth = $dbh->prepare(q{
        INSERT INTO radpostauth (username, pass, reply, authdate)
        VALUES (?, ?, ?, NOW())
    });
    
    if ($sth) {
        $sth->execute(
            $username,
            $RAD_REQUEST{'User-Password'} // '',
            $is_reject ? 'Access-Reject' : 'Access-Accept'
        );
        $sth->finish();
    }

    if (!$is_reject && $username ne '') {
        if (start_voucher_timer($dbh, $username)) {
            &radiusd::radlog(1, "PERL POST-AUTH: Voucher $username bound and timer started after Access-Accept");
        }
    }

    $dbh->disconnect();
    
    return RLM_MODULE_OK;
}

1;
