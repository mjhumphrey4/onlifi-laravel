#!/usr/bin/perl
#
# OnLiFi Multi-Tenant FreeRADIUS Module
# 
# This Perl module enables FreeRADIUS to route authentication requests
# to the correct tenant database based on the NAS (router) IP address.
#
# Installation:
# 1. Copy to /etc/freeradius/3.0/mods-config/perl/
# 2. Enable perl module: ln -s ../mods-available/perl /etc/freeradius/3.0/mods-enabled/
# 3. Configure perl module to use this script
# 4. Add 'perl' to authorize section in sites-available/default
#

use strict;
use warnings;
use DBI;

# Database configuration
my $central_db_host = "localhost";
my $central_db_name = "onlifi_central";
my $central_db_user = "radius_user";
my $central_db_pass = "your_secure_password";

# Cache for tenant database connections
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
# Get tenant database info from NAS IP
#
sub get_tenant_db {
    my ($nas_ip) = @_;
    
    # Check cache first
    return $tenant_cache{$nas_ip} if exists $tenant_cache{$nas_ip};
    
    my $dbh = DBI->connect(
        "DBI:mysql:database=$central_db_name;host=$central_db_host",
        $central_db_user,
        $central_db_pass,
        { RaiseError => 0, PrintError => 0 }
    );
    
    return undef unless $dbh;
    
    my $sth = $dbh->prepare(q{
        SELECT t.database_name, t.database_host, t.database_username, t.database_password
        FROM nas n
        JOIN tenants t ON n.tenant_id = t.id
        WHERE n.nasname = ?
        AND t.is_active = 1
    });
    
    $sth->execute($nas_ip);
    my $row = $sth->fetchrow_hashref();
    $sth->finish();
    $dbh->disconnect();
    
    if ($row) {
        $tenant_cache{$nas_ip} = $row;
        return $row;
    }
    
    return undef;
}

#
# Authorize - Check if user exists and get password
#
sub authorize {
    my $nas_ip = $RAD_REQUEST{'NAS-IP-Address'} // '';
    my $username = $RAD_REQUEST{'User-Name'} // '';
    
    # Get tenant database
    my $tenant = get_tenant_db($nas_ip);
    
    unless ($tenant) {
        &radiusd::radlog(1, "No tenant found for NAS: $nas_ip");
        return RLM_MODULE_REJECT;
    }
    
    # Connect to tenant database
    my $dbh = DBI->connect(
        "DBI:mysql:database=$tenant->{database_name};host=$tenant->{database_host}",
        $tenant->{database_username},
        $tenant->{database_password},
        { RaiseError => 0, PrintError => 0 }
    );
    
    unless ($dbh) {
        &radiusd::radlog(1, "Cannot connect to tenant database: $tenant->{database_name}");
        return RLM_MODULE_FAIL;
    }
    
    # Check radcheck for user
    my $sth = $dbh->prepare(q{
        SELECT attribute, value, op
        FROM radcheck
        WHERE username = ?
    });
    
    $sth->execute($username);
    
    my $found = 0;
    while (my $row = $sth->fetchrow_hashref()) {
        $found = 1;
        if ($row->{attribute} eq 'Cleartext-Password') {
            $RAD_CHECK{'Cleartext-Password'} = $row->{value};
        }
    }
    $sth->finish();
    
    unless ($found) {
        $dbh->disconnect();
        return RLM_MODULE_NOTFOUND;
    }
    
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
    my $nas_ip = $RAD_REQUEST{'NAS-IP-Address'} // '';
    my $username = $RAD_REQUEST{'User-Name'} // '';
    my $acct_status = $RAD_REQUEST{'Acct-Status-Type'} // '';
    
    my $tenant = get_tenant_db($nas_ip);
    return RLM_MODULE_NOOP unless $tenant;
    
    my $dbh = DBI->connect(
        "DBI:mysql:database=$tenant->{database_name};host=$tenant->{database_host}",
        $tenant->{database_username},
        $tenant->{database_password},
        { RaiseError => 0, PrintError => 0 }
    );
    
    return RLM_MODULE_FAIL unless $dbh;
    
    if ($acct_status eq 'Start') {
        my $sth = $dbh->prepare(q{
            INSERT INTO radacct 
            (acctsessionid, acctuniqueid, username, nasipaddress, 
             acctstarttime, calledstationid, callingstationid, framedipaddress)
            VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)
        });
        
        $sth->execute(
            $RAD_REQUEST{'Acct-Session-Id'} // '',
            $RAD_REQUEST{'Acct-Unique-Session-Id'} // '',
            $username,
            $nas_ip,
            $RAD_REQUEST{'Called-Station-Id'} // '',
            $RAD_REQUEST{'Calling-Station-Id'} // '',
            $RAD_REQUEST{'Framed-IP-Address'} // ''
        );
        $sth->finish();
    }
    elsif ($acct_status eq 'Stop') {
        my $sth = $dbh->prepare(q{
            UPDATE radacct SET
                acctstoptime = NOW(),
                acctsessiontime = ?,
                acctinputoctets = ?,
                acctoutputoctets = ?,
                acctterminatecause = ?
            WHERE acctuniqueid = ?
        });
        
        $sth->execute(
            $RAD_REQUEST{'Acct-Session-Time'} // 0,
            $RAD_REQUEST{'Acct-Input-Octets'} // 0,
            $RAD_REQUEST{'Acct-Output-Octets'} // 0,
            $RAD_REQUEST{'Acct-Terminate-Cause'} // 'Unknown',
            $RAD_REQUEST{'Acct-Unique-Session-Id'} // ''
        );
        $sth->finish();
    }
    elsif ($acct_status eq 'Interim-Update') {
        my $sth = $dbh->prepare(q{
            UPDATE radacct SET
                acctupdatetime = NOW(),
                acctsessiontime = ?,
                acctinputoctets = ?,
                acctoutputoctets = ?
            WHERE acctuniqueid = ?
        });
        
        $sth->execute(
            $RAD_REQUEST{'Acct-Session-Time'} // 0,
            $RAD_REQUEST{'Acct-Input-Octets'} // 0,
            $RAD_REQUEST{'Acct-Output-Octets'} // 0,
            $RAD_REQUEST{'Acct-Unique-Session-Id'} // ''
        );
        $sth->finish();
    }
    
    $dbh->disconnect();
    return RLM_MODULE_OK;
}

#
# Post-auth - Log authentication result
#
sub post_auth {
    my $nas_ip = $RAD_REQUEST{'NAS-IP-Address'} // '';
    my $username = $RAD_REQUEST{'User-Name'} // '';
    
    my $tenant = get_tenant_db($nas_ip);
    return RLM_MODULE_NOOP unless $tenant;
    
    my $dbh = DBI->connect(
        "DBI:mysql:database=$tenant->{database_name};host=$tenant->{database_host}",
        $tenant->{database_username},
        $tenant->{database_password},
        { RaiseError => 0, PrintError => 0 }
    );
    
    return RLM_MODULE_NOOP unless $dbh;
    
    my $sth = $dbh->prepare(q{
        INSERT INTO radpostauth (username, pass, reply, authdate)
        VALUES (?, ?, ?, NOW())
    });
    
    $sth->execute(
        $username,
        $RAD_REQUEST{'User-Password'} // '',
        'Access-Accept'
    );
    $sth->finish();
    $dbh->disconnect();
    
    return RLM_MODULE_OK;
}

1;
