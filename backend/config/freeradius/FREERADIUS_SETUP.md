# OnLiFi FreeRADIUS Setup

Use [ONLIFI_RADIUS_RUNBOOK.md](ONLIFI_RADIUS_RUNBOOK.md) as the current setup guide.

Important correction: dynamic/public MikroTik routers use one global RADIUS client secret. FreeRADIUS cannot select a per-router secret from `NAS-Identifier` because the packet must be decoded before `NAS-Identifier` can be trusted.

The production flow is:

1. FreeRADIUS accepts the router packet with the global shared secret from `clients.conf`.
2. MikroTik sends its system identity as `NAS-Identifier`.
3. `multi_tenant.pl` looks up `central.nas.router_identifier`.
4. The matching NAS row provides `tenant_id` and `site_id`.
5. The Perl module connects to the correct tenant/site database and checks `radcheck` / `radreply`.

Per-site secrets should only be used later when every router has a stable VPN private IP and FreeRADIUS can match each router by source IP.
