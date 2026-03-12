# OnLiFi Laravel - Payment & Voucher Management System

A comprehensive Laravel-based payment and voucher management system with MikroTik integration, YO! Payments support, and multi-tenant capabilities.

## Project Structure

```
onlifi-laravel/
├── backend/          # Laravel API backend
├── frontend/         # React/Vite dashboard frontend
└── data/            # Database schemas, documentation, scripts, and certificates
```

## Features

### Payment Processing
- YO! Payments integration for mobile money transactions
- Real-time payment status tracking
- IPN (Instant Payment Notification) handling
- Transaction history and reporting

### Voucher Management
- Generate voucher batches
- Automatic voucher assignment on successful payment
- Voucher validation and tracking
- Multiple voucher types with different durations and limits
- Sales point management

### MikroTik Integration
- RouterOS API integration
- FreeRADIUS authentication
- Active user monitoring
- Router telemetry collection
- Automated voucher provisioning

### Multi-tenant Support
- Central authentication database
- Separate databases per tenant
- User management and permissions
- Activity logging

### Dashboard
- Modern React-based UI with shadcn/ui components
- Real-time statistics and charts
- Transaction monitoring
- Voucher management interface
- Router management and monitoring

## Quick Start

### Prerequisites

- PHP >= 8.1
- Node.js >= 18
- MySQL >= 5.7
- Composer
- npm or pnpm

### Backend Setup

1. Navigate to backend folder:
```bash
cd backend
```

2. Install dependencies:
```bash
composer install
```

3. Configure environment:
```bash
cp .env.example .env
php artisan key:generate
```

4. Update `.env` with your database and API credentials

5. Run migrations:
```bash
php artisan migrate
```

6. Start development server:
```bash
php artisan serve
```

Backend will be available at `http://localhost:8000`

### Frontend Setup

1. Navigate to frontend folder:
```bash
cd frontend
```

2. Install dependencies:
```bash
npm install
# or
pnpm install
```

3. Configure environment:
```bash
cp .env.example .env
```

4. Update `.env` with backend API URL:
```
VITE_API_URL=http://localhost:8000/api
```

5. Start development server:
```bash
npm run dev
# or
pnpm dev
```

Frontend will be available at `http://localhost:5173`

### Database Setup

1. Create databases:
```sql
CREATE DATABASE payment_mikrotik CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE onlifi_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Import schemas (optional - Laravel migrations will create tables):
```bash
mysql -u yo -p payment_mikrotik < data/database/mikrotik_schema.sql
mysql -u yo -p onlifi_central < data/database/central_auth_schema.sql
```

## Configuration

### Backend Configuration

Edit `backend/.env`:

```env
# Database
DB_DATABASE=payment_mikrotik
DB_USERNAME=yo
DB_PASSWORD=your_password

# YO! Payments
YOAPI_USERNAME=your_username
YOAPI_PASSWORD=your_password
YOAPI_MODE=production

# Site URL
SITE_URL=https://yourdomain.com/
FRONTEND_URL=https://yourdomain.com

# MikroTik
MIKROTIK_DEFAULT_HOST=192.168.88.1
MIKROTIK_DEFAULT_USERNAME=admin
MIKROTIK_DEFAULT_PASSWORD=admin
```

### Frontend Configuration

Edit `frontend/.env`:

```env
VITE_API_URL=http://localhost:8000/api
VITE_APP_NAME=OnLiFi Payment System
VITE_ORIGIN_SITE=SiteA
```

## API Documentation

### Payment Endpoints

- `POST /api/payments/initiate` - Initiate payment
- `GET /api/payments/check-status` - Check transaction status
- `POST /api/payments/ipn` - Payment notification webhook

### Voucher Endpoints

- `GET /api/vouchers` - List vouchers
- `POST /api/vouchers/generate-batch` - Generate voucher batch
- `POST /api/vouchers/validate` - Validate voucher
- `GET /api/vouchers/statistics` - Get statistics

### Router Endpoints

- `GET /api/routers` - List routers
- `POST /api/routers` - Add router
- `POST /api/routers/{id}/test-connection` - Test connection
- `POST /api/routers/telemetry/ingest` - Ingest telemetry

### Transaction Endpoints

- `GET /api/transactions` - List transactions
- `GET /api/transactions/statistics` - Get statistics
- `GET /api/transactions/daily-report` - Get daily report

## Deployment

### Production Backend

1. Set environment to production:
```env
APP_ENV=production
APP_DEBUG=false
```

2. Optimize Laravel:
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
composer install --optimize-autoloader --no-dev
```

3. Set proper permissions:
```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### Production Frontend

1. Build for production:
```bash
npm run build
# or
pnpm build
```

2. Deploy `dist/` folder to your web server

### Web Server Configuration

#### Nginx (Backend)

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;
    root /path/to/backend/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

#### Nginx (Frontend)

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/frontend/dist;

    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

## MikroTik Setup

1. Install the telemetry script from `data/scripts/mikrotik-telemetry-script.rsc`
2. Configure FreeRADIUS to use the MySQL database
3. Set up hotspot with RADIUS authentication
4. Configure voucher profiles in MikroTik

## Troubleshooting

### Backend Issues

- **Database connection failed**: Check database credentials in `.env`
- **Permission denied**: Run `chmod -R 775 storage bootstrap/cache`
- **Class not found**: Run `composer dump-autoload`

### Frontend Issues

- **API calls failing**: Check `VITE_API_URL` in `.env`
- **CORS errors**: Update `CORS_ALLOWED_ORIGINS` in backend `.env`
- **Build errors**: Clear node_modules and reinstall

### Payment Issues

- **IPN not received**: Check firewall and ensure IPN URL is accessible
- **Payment verification failed**: Verify YO! Payments certificates are in place
- **Transaction stuck**: Check logs in `backend/storage/logs/`

## Development

### Running Tests

Backend:
```bash
cd backend
php artisan test
```

### Code Quality

Backend:
```bash
./vendor/bin/pint
```

Frontend:
```bash
npm run lint
```

## Documentation

Comprehensive documentation is available in the `data/documentation/` folder:

- Deployment guides
- Feature documentation
- API reference
- Troubleshooting guides
- Migration guides

## Security

- Never commit `.env` files
- Keep YO! Payments credentials secure
- Use HTTPS in production
- Regularly update dependencies
- Enable Laravel's security features
- Implement rate limiting on API endpoints

## License

MIT License

## Support

For issues, questions, or contributions, please refer to the project documentation or contact the development team.

---

**Built with Laravel, React, and modern web technologies**
