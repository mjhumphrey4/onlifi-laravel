# Onlifi FreeRADIUS Multi-Tenant Configuration

## Overview

This configuration enables FreeRADIUS to authenticate hotspot users across multiple tenants, where each MikroTik router can have its own unique RADIUS secret and is identified by its MikroTik Identity (system name).

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     ONLIFI MULTI-TENANT RADIUS FLOW                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  MikroTik Router                                                            │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │ Identity: "ACME-ROUTER-001"                                         │    │
│  │ RADIUS Secret: "unique_secret_for_this_router"                      │    │
│  │ Sends: NAS-Identifier = "ACME-ROUTER-001"                          │    │
│  └────────────────────────────────────────────────────────────────────┘    │
│                              │                                              │
│                              ▼                                              │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │                    FreeRADIUS Server                                │    │
│  │  1. Receives request with NAS-Identifier                           │    │
│  │  2. Looks up NAS-Identifier in central `nas` table                 │    │
│  │  3. Validates RADIUS secret matches                                │    │
│  │  4. Gets tenant_id from NAS record                                 │    │
│  │  5. Connects to tenant's database                                  │    │
│  │  6. Authenticates user against tenant's radcheck table             │    │
│  │  7. Returns reply attributes from tenant's radreply table          │    │
│  └────────────────────────────────────────────────────────────────────┘    │
│                              │                                              │
│              ┌───────────────┼───────────────┐                             │
│              ▼               ▼               ▼                              │
│  ┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐           │
│  │ Central Database │ │ Tenant: ACME     │ │ Tenant: BETA     │           │
│  │ onlifi_central   │ │ tenant_acme      │ │ tenant_beta      │           │
│  │                  │ │                  │ │                  │           │
│  │ Tables:          │ │ Tables:          │ │ Tables:          │           │
│  │ - nas            │ │ - radcheck       │ │ - radcheck       │           │
│  │ - tenants        │ │ - radreply       │ │ - radreply       │           │
│  │                  │ │ - radacct        │ │ - radacct        │           │
│  │                  │ │ - vouchers       │ │ - vouchers       │           │
│  └──────────────────┘ └──────────────────┘ └──────────────────┘           │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Key Concept: MikroTik Identity as NAS-Identifier

Since MikroTik routers don't have public IPs:
- Each router's **System Identity** is used as the unique identifier
- The identity is sent in RADIUS requests as `NAS-Identifier`
- FreeRADIUS looks up this identifier in the central `nas` table
- Each router can have its own unique RADIUS secret

## Files in this Directory

| File | Purpose |
|------|---------|
| `clients.conf` | Dynamic client loading from database |
| `sql.conf` | SQL module configuration |
| `queries.conf` | SQL queries for central database |
| `queries_tenant.conf` | SQL queries for tenant databases |
| `multi_tenant.pl` | Perl module for dynamic tenant routing |
| `default` | Virtual server configuration |

## Installation Steps

See `FREERADIUS_SETUP.md` for complete installation instructions.
