# TODO

## Billing Completeness

- Add invoice and receipt records for subscription payments, with downloadable PDF receipts for tenants and exportable admin ledgers.
- Add subscription reminder notifications before trial expiry and before paid subscription expiry.
- Add an admin subscription ledger page with filters for pending, failed, and successful renewals.
- Add retry and reconciliation tools for mobile money payments that remain pending too long.
- Add webhook/IPN audit logging with raw payloads, verification result, and operator-friendly diagnostics.
- Add per-tenant subscription price overrides and optional plan tiers once the default monthly charge is stable.
- Add automated tests for trial approval, expiry dashboard lock, successful mobile money renewal, and failed payment handling.
- Add a scheduled job that marks stale pending subscription payments as failed after the mobile money provider timeout.
- Add captive portal end-to-end tests with MikroTik variables, mobile money payment confirmation, and automatic voucher login.
- Add SMS delivery provider integration beyond the current Comms placeholder and track failed sends without consuming credits.
- Add FreeRADIUS deployment checks that verify the Perl module, environment variables, dynamic clients, and tenant DB connectivity.
- Add customer-facing forgot-password flows with signed reset links instead of only admin-triggered password reset notices.
- Add a provider-specific SMS adapter once the final SMS API documentation is supplied.
