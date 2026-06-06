# Payments Manual

Standalone manual payment management system kept inside this repository.

This project is intentionally separate from the root `backend` and `frontend` applications. Its Laravel backend lives in `Payments-Manual/backend`, its React frontend lives in `Payments-Manual/UI`, and the original PHP payment flow remains in `Payments-Manual/yo-onlifi`.

## Backend

```bash
cd Payments-Manual/backend
composer install
php artisan key:generate
php artisan migrate --force
php artisan serve --host=127.0.0.1 --port=8001
```

Local admin token:

```text
payments-manual-admin
```

Change `PAYMENTS_MANUAL_ADMIN_TOKEN` in `Payments-Manual/backend/.env` before production.

## Frontend

```bash
cd Payments-Manual/UI
npm install
npm run dev
```

The UI defaults to `http://127.0.0.1:8001/api`. Override with:

```bash
VITE_API_URL=http://127.0.0.1:8001/api npm run dev
```

## Scope

- Admin dashboard for the manual payment system.
- Provider configuration for Yo Uganda and fallback providers.
- Callback endpoint management.
- Withdrawal API configuration placeholders for future Onlifi integration.
- Legacy transaction dashboard and search through configurable database settings.
- SMS functionality is intentionally disabled until the new provider is supplied.
