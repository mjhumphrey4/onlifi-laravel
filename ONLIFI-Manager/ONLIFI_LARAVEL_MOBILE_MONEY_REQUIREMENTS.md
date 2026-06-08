# ONLIFI-Laravel Mobile Money Requirements

This file is for the Laravel project only. No Laravel code was changed by ONLIFI-Manager.

## Login

No separate mobile money login should happen inside the Android app.

Use the existing ONLIFI login flow:

- `POST /api/tenant/login`
- Optional existing 2FA response: `requires_2fa`, `two_factor_token`
- Store the returned tenant bearer token in the Android app
- Protect app reopen with Android device credential: PIN, pattern, password, or biometric

Mobile money provider credentials should stay on the Laravel server. The mobile app should never ask the user for MTN, Airtel, Yo, IOTEC, or payment-provider API credentials.

## Required Mobile Endpoints

The native Android app already uses these existing endpoints:

- `GET /api/sites`
- `GET /api/dashboard/stats`
- `GET /api/clients?limit=100`
- `GET /api/transactions?status=success&per_page=10`
- `GET /api/transactions/performance?period=today`
- `GET /api/vouchers/statistics`
- `GET /api/vouchers/types`
- `POST /api/vouchers/generate-batch`
- `POST /api/tenant/support-tickets`

All tenant data requests must continue accepting:

```http
Authorization: Bearer {tenant_token}
X-Site-ID: {site_id}
```

## Missing Withdrawal Support

The current React frontend has withdrawal helpers as placeholders, so the Android app cannot submit real withdrawal requests yet.

Add these Laravel endpoints:

```http
GET /api/tenant/mobile-wallet
GET /api/tenant/withdrawals
POST /api/tenant/withdrawals
```

Suggested `GET /api/tenant/mobile-wallet` response:

```json
{
  "site_id": 1,
  "site_name": "Main Site",
  "available_balance": 125000,
  "pending_withdrawals": 30000,
  "total_withdrawn": 400000,
  "currency": "UGX",
  "cache": {
    "source": "database",
    "ttl_seconds": 300
  }
}
```

Suggested `POST /api/tenant/withdrawals` request:

```json
{
  "site_id": 1,
  "amount": 50000,
  "phone_number": "256XXXXXXXXX"
}
```

Suggested response:

```json
{
  "message": "Withdrawal request submitted",
  "withdrawal": {
    "id": 10,
    "transaction_reference": "WD-20260608-00010",
    "amount": 50000,
    "phone_number": "256XXXXXXXXX",
    "status": "pending",
    "site_id": 1,
    "created_at": "2026-06-08T12:00:00Z"
  }
}
```

## Rules To Enforce In Laravel

- Require `auth:sanctum`.
- Resolve tenant and site from the bearer token plus `X-Site-ID`.
- Reject withdrawals if the site does not belong to the user.
- Reject amounts below the platform minimum.
- Reject amounts greater than available balance.
- Normalize phone numbers to `256XXXXXXXXX`.
- Store an immutable withdrawal audit log.
- Submit provider payout from Laravel only, not from Android.
- Return cached wallet summaries where possible for fast mobile loading.

## Balance Formula

Expose an exact server-side balance. Do not make Android calculate settlement balance from dashboard totals.

Recommended:

```text
available_balance = successful_mobile_money_net_amount
                  + eligible_physical_voucher_revenue
                  - successful_withdrawals
                  - pending_withdrawals
                  - platform_or_provider_fees
```

If physical voucher revenue is cash collected outside mobile money, decide whether it should be withdrawable before including it in `available_balance`.
