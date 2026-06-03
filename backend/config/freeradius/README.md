# OnLiFi FreeRADIUS Configuration

Current setup guide: [ONLIFI_RADIUS_RUNBOOK.md](ONLIFI_RADIUS_RUNBOOK.md).

## Current Architecture

- Dynamic/public MikroTik routers share one global RADIUS client secret.
- The router identity is the site identifier, for example `main-router22-ONLIFI-1`.
- MikroTik sends that identity as `NAS-Identifier`.
- FreeRADIUS accepts packets using the shared secret in `clients.conf`.
- `multi_tenant.pl` looks up `central.nas.router_identifier`.
- The matching row selects the correct `tenant_id` and `site_id`.
- Voucher authentication is performed against the selected tenant/site database.

## Files

| File | Purpose |
| --- | --- |
| `clients.conf` | Defines the shared dynamic MikroTik client. |
| `default` | FreeRADIUS virtual server using Perl for auth/accounting. |
| `perl` | FreeRADIUS Perl module config that loads `multi_tenant.pl`. |
| `multi_tenant.pl` | Routes requests to the correct tenant/site database. |
| `sql.conf` | Optional support/debug config only. Do not enable it for the current production flow. |
| `ONLIFI_RADIUS_RUNBOOK.md` | Practical server setup and troubleshooting steps. |

Per-router RADIUS secrets should only be enabled later when routers have stable VPN private source IPs.

For production, make sure `/etc/freeradius/3.0/mods-enabled/sql` is removed. The active OnLiFi flow uses `mods-enabled/perl`; enabling SQL can cause FreeRADIUS parse errors before tenant routing starts.
