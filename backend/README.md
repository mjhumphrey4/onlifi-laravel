# OnLiFi Laravel Backend

This is the Laravel backend for the OnLiFi Payment & Voucher Management System.

## Features

- **Payment Processing**: YO! Payments integration for mobile money transactions
- **Voucher Management**: Generate, assign, and track WiFi vouchers
- **MikroTik Integration**: Router management and telemetry collection
- **Multi-tenant Support**: Separate databases per tenant
- **RESTful API**: Complete API for frontend integration
- **Transaction Tracking**: Comprehensive transaction logging and reporting

## Requirements

- PHP >= 8.1
- MySQL >= 5.7
- Composer
- Laravel 10.x

## Installation

1. Install dependencies:
```bash
composer install
```

2. Copy environment file:
```bash
cp .env.example .env
```

3. Generate application key:
```bash
php artisan key:generate
```

4. Configure your database in `.env`:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=payment_mikrotik
DB_USERNAME=yo
DB_PASSWORD=password
```

5. Configure YO! Payments credentials:
```
YOAPI_USERNAME=your_username
YOAPI_PASSWORD=your_password
YOAPI_MODE=production
```

6. Run migrations:
```bash
php artisan migrate
```

7. Start the development server:
```bash
php artisan serve
```

## API Endpoints

### Payments
- `POST /api/payments/initiate` - Initiate payment
- `GET /api/payments/check-status` - Check transaction status
- `POST /api/payments/ipn` - Payment notification webhook
- `POST /api/payments/failure` - Payment failure webhook

### Vouchers
- `GET /api/vouchers` - List vouchers
- `GET /api/vouchers/{id}` - Get voucher details
- `POST /api/vouchers/generate-batch` - Generate voucher batch
- `POST /api/vouchers/validate` - Validate voucher credentials
- `GET /api/vouchers/statistics` - Get voucher statistics

### MikroTik Routers
- `GET /api/routers` - List routers
- `POST /api/routers` - Add new router
- `GET /api/routers/{id}` - Get router details
- `PUT /api/routers/{id}` - Update router
- `DELETE /api/routers/{id}` - Delete router
- `POST /api/routers/{id}/test-connection` - Test router connection
- `GET /api/routers/{id}/active-users` - Get active users
- `POST /api/routers/telemetry/ingest` - Ingest telemetry data

### Transactions
- `GET /api/transactions` - List transactions
- `GET /api/transactions/{id}` - Get transaction details
- `GET /api/transactions/statistics` - Get transaction statistics
- `GET /api/transactions/daily-report` - Get daily report

## Configuration

### Timezone
The system uses East Africa Time (EAT - UTC+3) by default. Configure in `.env`:
```
APP_TIMEZONE=Africa/Nairobi
```

### CORS
Configure allowed origins in `.env`:
```
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:3000
```

### SMS Notifications
Configure SMS provider in `.env`:
```
SMS_PROVIDER=comms
SMS_API_KEY=your_api_key
SMS_SENDER_ID=OnLiFi
```

## Development

### Running Tests
```bash
php artisan test
```

### Code Style
```bash
./vendor/bin/pint
```

### Clear Cache
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

## Production Deployment

1. Set environment to production:
```
APP_ENV=production
APP_DEBUG=false
```

2. Optimize for production:
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

3. Set proper permissions:
```bash
chmod -R 775 storage bootstrap/cache
```

## License

MIT License
