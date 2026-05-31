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
| `multi_tenant.pl` | Routes requests to the correct tenant/site database. |
| `sql.conf` | SQL module config, retained for support use; client loading is disabled for dynamic routers. |
| `ONLIFI_RADIUS_RUNBOOK.md` | Practical server setup and troubleshooting steps. |

Per-router RADIUS secrets should only be enabled later when routers have stable VPN private source IPs.
