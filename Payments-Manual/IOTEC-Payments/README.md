# IOTEC Payments

Standalone IOTEC payment management system kept inside this repository.

This project is separate from:

- The root Laravel and React application.
- The `Payments-Manual/backend` Yo/manual-payment dashboard.
- The legacy PHP provider files in `Payments-Manual/yo-onlifi/yo-onlifi/IOTEC`.

The Laravel backend lives in `Payments-Manual/IOTEC-Payments/backend`.
The React frontend lives in `Payments-Manual/IOTEC-Payments/UI`.

## Backend

```bash
cd Payments-Manual/IOTEC-Payments/backend
composer install
php artisan key:generate
php artisan migrate --force
php artisan serve --host=127.0.0.1 --port=8011
```

Local admin token:

```text
iotec-payments-admin
```

Change `IOTEC_ADMIN_TOKEN` in `Payments-Manual/IOTEC-Payments/backend/.env` before production.

## Frontend

```bash
cd Payments-Manual/IOTEC-Payments/UI
npm install
npm run dev
```

The UI defaults to:

```text
http://127.0.0.1:8011/api
```

Override with:

```bash
VITE_API_URL=http://127.0.0.1:8011/api npm run dev
```

## Scope

- IOTEC dashboard metrics and transaction search.
- IOTEC OAuth client profile management.
- Wallet ID, auth URL, API base URL, collect endpoint, and status endpoint settings.
- Callback endpoint and expected payload-field management.
- Polling settings and queue visibility.
- IOTEC fee visibility for telecom fees, platform fees, and net revenue.
- SMS functionality remains disabled until the new SMS provider is supplied.
