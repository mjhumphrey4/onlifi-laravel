# OnLiFi FreeRADIUS Runbook

## Core Design

For routers with dynamic/public source IPs, FreeRADIUS cannot choose a different secret per router from `NAS-Identifier`. The packet is decoded with a client secret before `NAS-Identifier` can be trusted.

Use this production path:

- One global shared RADIUS secret for all provisioned routers.
- Each site/router has a unique MikroTik identity, for example `main-router22-ONLIFI-1`.
- MikroTik sends that identity as `NAS-Identifier`.
- FreeRADIUS accepts the packet with the global secret.
- `multi_tenant.pl` looks up `central.nas.router_identifier = NAS-Identifier`.
- The matching `nas` row provides `tenant_id` and `site_id`.
- The Perl module connects to the site database when `sites.database_*` is configured, otherwise it falls back to the tenant database.
- The selected tenant/site database is checked for `radcheck` / `radreply`.
- On successful auth, the voucher becomes `in_use`, is bound to the first device MAC, and the expiry timer starts.
- When the voucher reaches `expires_at`, it is flagged `used`, removed from RADIUS, and kicked/disconnected where router API access is available.
- Accounting updates usage/reporting data. Expiry cleanup still works from `expires_at` even if accounting is temporarily unavailable.

Per-site RADIUS secrets are only practical after every router has a stable source IP, such as an SSTP VPN private IP. Then `nasname` can be the router VPN IP and FreeRADIUS can load unique clients safely.

## Laravel Values That Must Match

Set these on the backend server:

```env
RADIUS_SERVER_IP=<public-or-private-ip-of-freeradius>
RADIUS_AUTH_PORT=1812
RADIUS_ACCT_PORT=1813
RADIUS_SHARED_SECRET=<one-strong-global-secret>
```

In the admin system settings, make sure `radius_server_ip`, `radius_auth_port`, `radius_acct_port`, and `radius_shared_secret` match the same values.

The provisioning script uses:

- `/system identity set name=<nas.router_identifier>`
- `/radius add service=hotspot,login address=<radius_server_ip> secret=<radius_shared_secret>`
- `/ip hotspot profile ... use-radius=yes radius-accounting=yes login-by=http-pap`

## FreeRADIUS Installation

On Ubuntu:

```bash
sudo apt update
sudo apt install -y freeradius freeradius-mysql freeradius-utils libdbi-perl libdbd-mysql-perl
sudo systemctl stop freeradius
```

Copy the OnLiFi config files:

```bash
cd /etc/freeradius/3.0

sudo cp /var/www/onlifi/backend/config/freeradius/clients.conf clients.conf
sudo cp /var/www/onlifi/backend/config/freeradius/default sites-available/default
sudo cp /var/www/onlifi/backend/config/freeradius/perl mods-available/perl
sudo ln -sf ../sites-available/default sites-enabled/default

sudo mkdir -p mods-config/perl
sudo cp /var/www/onlifi/backend/config/freeradius/multi_tenant.pl mods-config/perl/onlifi_multi_tenant.pl
sudo chmod +x mods-config/perl/onlifi_multi_tenant.pl
```

Edit `/etc/freeradius/3.0/clients.conf` and replace:

```text
secret = onlifi_radius_secret_change_me
```

with the exact `RADIUS_SHARED_SECRET` used by Laravel.

Enable modules:

```bash
cd /etc/freeradius/3.0/mods-enabled
sudo rm -f sql
sudo ln -sf ../mods-available/perl perl
```

Do not enable the SQL module for the current OnLiFi production flow. The `default` virtual server calls `perl`, and `multi_tenant.pl` chooses the correct tenant/site database from `NAS-Identifier`. Enabling `mods-enabled/sql` can stop FreeRADIUS during config parsing with errors like `Reference "${client_table}" not found` or `Reference "${ENV_RADIUS_DB_PASSWORD}" not found`.

Edit `/etc/freeradius/3.0/mods-available/perl`:

```text
perl {
    filename = /etc/freeradius/3.0/mods-config/perl/onlifi_multi_tenant.pl
    func_authorize = authorize
    func_authenticate = authenticate
    func_accounting = accounting
    func_start_accounting = accounting
    func_stop_accounting = accounting
    func_post_auth = post_auth
    perl_flags = "-w"
}
```

Edit `/etc/freeradius/3.0/mods-config/perl/onlifi_multi_tenant.pl`:

```perl
my $central_db_host = "localhost";
my $central_db_name = "onlifi_central";
my $central_db_user = "radius_user";
my $central_db_pass = "your_radius_db_password";
```

## Database User

Create a DB user FreeRADIUS can use:

```sql
CREATE USER 'radius_user'@'localhost' IDENTIFIED BY 'your_radius_db_password';
GRANT SELECT ON onlifi_central.tenants TO 'radius_user'@'localhost';
GRANT SELECT ON onlifi_central.nas TO 'radius_user'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `onlifi\_%`.* TO 'radius_user'@'localhost';
FLUSH PRIVILEGES;
```

Adjust the tenant DB pattern if your tenant/site databases use a different prefix.

After deploying schema changes, migrate every tenant and site database:

```bash
cd /var/www/onlifi/backend
php artisan onlifi:tenants:migrate
```

## Firewall

Open RADIUS ports on the FreeRADIUS server:

```bash
sudo ufw allow 1812/udp
sudo ufw allow 1813/udp
sudo ufw allow 3799/udp
```

If the server is behind a cloud firewall, also allow UDP `1812` and `1813` there.

## Start In Debug Mode First

```bash
sudo freeradius -XC
sudo systemctl stop freeradius
sudo freeradius -X
```

Keep `freeradius -X` open while testing a MikroTik login.

If no log appears at all when a user tries to log in:

- The MikroTik cannot reach the RADIUS IP/port.
- A firewall/security group is blocking UDP 1812.
- The MikroTik `/radius` entry points to the wrong IP.
- The hotspot profile is not using RADIUS.

Confirm whether packets reach the server:

```bash
sudo tcpdump -ni any udp port 1812 or udp port 1813
```

If `tcpdump` shows nothing, the problem is network/routing/firewall before FreeRADIUS. If `tcpdump` shows packets but `freeradius -X` shows nothing, FreeRADIUS is not listening on the expected interface/port or another process is bound to the port.

If logs show unknown client:

- `clients.conf` is not installed or the `onlifi_dynamic_routers` client is wrong.

If logs show invalid secret:

- MikroTik `/radius secret` does not equal `clients.conf` global secret.

If logs show `No tenant found for router identifier`:

- MikroTik `/system identity` does not match `central.nas.router_identifier`.

If logs show `User not found in radcheck`:

- The voucher is not synced into the selected tenant/site database.
- The voucher belongs to a different site.
- The NAS is linked to a site, but the deployed Perl script is still connecting to the tenant DB instead of `sites.database_name`.
- Make sure the `User-Name` in `freeradius -X` is the same voucher you are syncing. If the log says `User-Name = "136485"`, syncing `4RPYDL` will not fix that login attempt.
- Diagnose the exact router/voucher lookup with:

```bash
cd /var/www/onlifi/backend
php artisan onlifi:radius:diagnose --router=main-router22-ONLIFI-1 --voucher=136485
```

- Repair a known router/site with:

```bash
cd /var/www/onlifi/backend
php artisan onlifi:radius:sync-active --router=main-router22-ONLIFI-1
```

- Repair one voucher and assign it to the selected site if it was created before site IDs were enforced:

```bash
php artisan onlifi:radius:sync-active --router=main-router22-ONLIFI-1 --voucher=4RPYDL --backfill-site
```

If logs show `Empty User-Password received`, the MikroTik page submitted the voucher as username but did not populate the hidden password field. Re-download/provision `login.html` after deploying the latest backend so the PAP password sync script is included.

Confirm FreeRADIUS is running the latest Perl module. If `freeradius -X` does not show `PERL DIAG` or `Empty User-Password received` lines on failures, the deployed Perl file is stale or the module path is wrong:

```bash
sudo grep -n "filename\\|PERL DIAG\\|Empty User-Password" \
  /etc/freeradius/3.0/mods-available/perl \
  /etc/freeradius/3.0/mods-config/perl/onlifi_multi_tenant.pl
```

## MikroTik Verification Commands

Run these on the router:

```routeros
/system identity print
/radius print detail
/ip hotspot profile print detail
/ip hotspot print detail
/log print where message~"radius"
```

Expected:

- Identity equals the site router identifier shown in OnLiFi.
- `/radius` has the FreeRADIUS server IP, ports `1812/1813`, and global secret.
- Hotspot profile has `use-radius=yes`, `radius-accounting=yes`, and `login-by=http-pap`.

## Direct Test From FreeRADIUS Server

Use a real voucher code and the site router identifier:

```bash
echo 'User-Name=VOUCHER_CODE,User-Password=VOUCHER_CODE,NAS-Identifier=main-router22-ONLIFI-1' \
  | radclient -x 127.0.0.1 auth testing123
```

`testing123` is only for the local `client localhost` test in `clients.conf`. MikroTik routers must use the global `onlifi_dynamic_routers` shared secret configured in `clients.conf` and provisioned to `/radius`.

Expected result:

```text
Received Access-Accept
```

If this passes locally but MikroTik still shows no RADIUS logs, the problem is network/firewall between MikroTik and FreeRADIUS.

If the same voucher works on one device but rejects another, that is expected. One voucher is bound to the first `Calling-Station-Id` that successfully authenticates.

## Accounting Test

Voucher timers and used/expired states depend on accounting packets. Authentication can work while accounting fails if UDP `1813` is blocked or the accounting handler cannot write to the selected site database.

First confirm FreeRADIUS is listening:

```bash
sudo ss -lunp | grep ':1813'
sudo tcpdump -ni any udp port 1813
```

Then send a direct accounting Start packet from the FreeRADIUS server:

```bash
echo 'User-Name=136485,NAS-Identifier=main-router22-ONLIFI-1,Acct-Status-Type=Start,Acct-Session-Id=test-136485-1,NAS-IP-Address=127.0.0.1,Framed-IP-Address=10.10.0.253,Calling-Station-Id=BC:24:11:A6:70:A2,Called-Station-Id=onlifi-hotspot,NAS-Port-Type=Wireless-802.11,NAS-Port-Id=onlifi-lan' \
  | radclient -x 127.0.0.1 acct testing123
```

Expected result:

```text
Received Accounting-Response
```

Expected `freeradius -X` lines:

```text
PERL ACCOUNTING: User=136485, Status=Start
PERL ACCOUNTING: Voucher 136485 marked used and timer started
```

If the direct accounting test works but MikroTik logs `RADIUS accounting request not sent: no response`, open UDP `1813` between the router and the FreeRADIUS server, including host firewall and cloud firewall. Also confirm the router has the same accounting port:

```routeros
/radius print detail
/ip hotspot profile print detail
```

Expected MikroTik profile values include `use-radius=yes`, `radius-accounting=yes`, and `radius-interim-update=1m`.

If the direct accounting test gets `No reply from server` on `127.0.0.1`, check the local FreeRADIUS accounting listener and loaded site config before debugging MikroTik:

```bash
sudo ss -lunp | grep ':1813'
sudo ls -l /etc/freeradius/3.0/sites-enabled/default
sudo grep -n "type = acct\\|port = 1813\\|accounting" /etc/freeradius/3.0/sites-enabled/default
sudo freeradius -X
```

While `freeradius -X` is open, repeat the accounting `radclient` command. If no request appears in debug output, FreeRADIUS is not listening on UDP `1813` with the OnLiFi virtual server. If a request appears but no response is sent, the Perl accounting handler or database write is failing; check for `PERL ACCOUNTING ERROR` lines and run tenant/site migrations.

If debug output shows `func =  exit status= Undefined subroutine &main:: called`, the Perl module is configured with blank accounting function overrides. Copy the bundled `backend/config/freeradius/perl` to `/etc/freeradius/3.0/mods-available/perl` so `func_start_accounting` and `func_stop_accounting` both point to `accounting`.
