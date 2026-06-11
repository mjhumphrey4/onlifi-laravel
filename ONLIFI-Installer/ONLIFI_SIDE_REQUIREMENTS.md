# ONLIFI Side Requirements For ONLIFI Installer

No ONLIFI Laravel files were changed. Add the items below on the ONLIFI side when you are ready to connect the Android app.

## Installer Accounts

Create a role or guard for installers.

Installer accounts should:

- Authenticate only against the installer mobile API.
- Be linked to the ONLIFI tenant/account they install devices for.
- Not receive dashboard/admin access unless separately granted.
- Receive a scoped API token that can only create installer device submissions and view its own installer identity.

Recommended token ability:

```text
installer:devices:create
```

## API Endpoints

Base URL used by the app:

```text
https://api.onlifi.net/api/installer
```

### POST /api/installer/login

Request JSON:

```json
{
  "email": "installer@example.com",
  "password": "secret"
}
```

Success response:

```json
{
  "token": "plain-text-sanctum-token",
  "installer_id": "123",
  "installer_name": "Installer Name"
}
```

Rules:

- Verify the user is an installer.
- Return `401` for invalid credentials.
- Return `403` if the account exists but is not allowed to use the installer app.

### POST /api/installer/devices

Authentication:

```text
Authorization: Bearer {token}
```

Request type:

```text
multipart/form-data
```

Fields:

```text
local_id
installer_id
device_name
ip_address
latitude
longitude
notes
created_at_device
front_photo
back_photo
```

Validation:

- `local_id`: required, unique idempotency key.
- `device_name`: required string.
- `ip_address`: required IPv4 address, unique in the tenant/account router list.
- `latitude`: required numeric value between `-90` and `90`.
- `longitude`: required numeric value between `-180` and `180`.
- `front_photo`: required image.
- `back_photo`: required image.
- `installer_id`: must match the authenticated installer or be ignored server-side in favor of the token user.

Success response:

```json
{
  "id": 456,
  "router_id": 789,
  "status": "created"
}
```

Recommended behavior:

- Use `local_id` as an idempotency key so repeated mobile retries do not create duplicates.
- Create or attach the router/device directly under the authenticated installer's ONLIFI tenant/account.
- Store photos remotely, for example under private storage or S3-compatible object storage.
- Return `409` if the IP address already belongs to another router in the same tenant/account.
- Return `422` for validation errors.

## Suggested Database Additions

Add an installer submissions table to preserve the mobile audit trail even after the router is created.

Suggested fields:

```text
id
tenant_id/account_id
installer_user_id
router_id
local_id
device_name
ip_address
latitude
longitude
front_photo_path
back_photo_path
notes
mobile_created_at
created_at
updated_at
```

The actual router table should receive:

```text
tenant_id/account_id
name/device_name
ip_address
latitude
longitude
installed_by_user_id
installed_at
installer_submission_id
```

## Uptime Kuma Integration

Leave Uptime Kuma creation for the ONLIFI Admin integration.

Recommended flow:

1. Android app uploads installer submission.
2. ONLIFI creates/updates the router list entry.
3. ONLIFI Admin queues a monitor creation job.
4. The job creates or updates the Uptime Kuma monitor for the router IP.
5. Store the Uptime Kuma monitor ID on the ONLIFI router record.

This keeps the Android app simple and prevents installers from needing Uptime Kuma credentials.
