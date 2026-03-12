# OnLiFi Multi-Tenant Setup Guide

Quick start guide for setting up the OnLiFi multi-tenant payment system.

## Prerequisites

- PHP 8.1+
- MySQL 5.7+
- Composer
- Node.js 18+

## Step 1: Database Setup

```sql
-- Connect to MySQL as root
mysql -u root -p

-- Create central database
CREATE DATABASE onlifi_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create central database user
CREATE USER 'onlifi_central'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON onlifi_central.* TO 'onlifi_central'@'localhost';

-- Grant permission to create tenant databases
GRANT CREATE, DROP ON *.* TO 'onlifi_central'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## Step 2: Backend Setup

```bash
cd backend

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Edit .env file
nano .env
```

Update these values in `.env`:

```env
# Application
APP_NAME="OnLiFi Payment System"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Central Database
CENTRAL_DB_HOST=127.0.0.1
CENTRAL_DB_PORT=3306
CENTRAL_DB_DATABASE=onlifi_central
CENTRAL_DB_USERNAME=onlifi_central
CENTRAL_DB_PASSWORD=your_secure_password

# YO! Payments API
YOAPI_USERNAME=your_yo_username
YOAPI_PASSWORD=your_yo_password
YOAPI_MODE=sandbox  # or 'production'

# Site URL
SITE_URL=http://localhost:8000/
FRONTEND_URL=http://localhost:5173
```

```bash
# Generate application key
php artisan key:generate

# Run central database migrations
php artisan migrate --database=central

# Start development server
php artisan serve
```

Backend is now running at `http://localhost:8000`

## Step 3: Create Your First Tenant

```bash
curl -X POST http://localhost:8000/api/admin/tenants \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My First Tenant",
    "admin_name": "Admin User",
    "admin_email": "admin@example.com",
    "admin_password": "SecurePassword123!"
  }'
```

**Save the response!** It contains:
- `api_key` - Use this in `X-API-Key` header
- `api_secret` - Use this in `X-API-Secret` header

Example response:
```json
{
  "message": "Tenant created successfully",
  "tenant": {
    "id": 1,
    "name": "My First Tenant",
    "slug": "my-first-tenant",
    "database_name": "onlifi_my_first_tenant_abc123"
  },
  "api_credentials": {
    "api_key": "onlifi_abc123...",
    "api_secret": "xyz789..."
  }
}
```

## Step 4: Test the API

```bash
# Set your credentials
API_KEY="onlifi_abc123..."
API_SECRET="xyz789..."

# Test payment initiation
curl -X POST http://localhost:8000/api/payments/initiate \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Secret: $API_SECRET" \
  -d '{
    "amount": 1000,
    "msisdn": "256771234567",
    "origin_site": "TestSite",
    "client_mac": "00:11:22:33:44:55",
    "voucher_type": "Feature_1000",
    "origin_url": "http://localhost:5173/payment"
  }'

# Check transactions
curl -X GET "http://localhost:8000/api/transactions" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Secret: $API_SECRET"
```

## Step 5: Frontend Setup (Optional)

```bash
cd ../frontend

# Install dependencies
npm install

# Copy environment file
cp .env.example .env

# Edit .env
nano .env
```

Update `.env`:
```env
VITE_API_URL=http://localhost:8000/api
VITE_APP_NAME=OnLiFi Payment System
```

```bash
# Start development server
npm run dev
```

Frontend is now running at `http://localhost:5173`

## Step 6: Add MikroTik Router

```bash
curl -X POST http://localhost:8000/api/routers \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Secret: $API_SECRET" \
  -d '{
    "name": "Main Router",
    "host": "192.168.88.1",
    "port": 8728,
    "username": "admin",
    "password": "your_mikrotik_password",
    "location": "Head Office"
  }'

# Test router connection
curl -X POST http://localhost:8000/api/routers/1/test-connection \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Secret: $API_SECRET"
```

## Step 7: Generate Vouchers

```bash
curl -X POST http://localhost:8000/api/vouchers/generate-batch \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Secret: $API_SECRET" \
  -d '{
    "voucher_type_id": 1,
    "quantity": 100,
    "group_name": "Test Batch 001"
  }'
```

## Creating Additional Tenants

Each tenant gets their own isolated database:

```bash
# Create Tenant 2
curl -X POST http://localhost:8000/api/admin/tenants \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Second Tenant",
    "admin_name": "Admin Two",
    "admin_email": "admin2@example.com",
    "admin_password": "SecurePassword456!"
  }'

# Save the new API credentials
# This tenant's data is completely isolated from Tenant 1
```

## Verification Checklist

- [ ] Backend server running on port 8000
- [ ] Central database created and migrated
- [ ] First tenant created successfully
- [ ] API credentials saved securely
- [ ] Test payment initiated successfully
- [ ] Transactions visible in API response
- [ ] MikroTik router added (if applicable)
- [ ] Router connection test passed (if applicable)
- [ ] Frontend running on port 5173 (if using)

## Common Issues

### "SQLSTATE[HY000] [1045] Access denied"
- Check database credentials in `.env`
- Verify user has proper permissions
- Test connection: `mysql -u onlifi_central -p onlifi_central`

### "Tenant not identified"
- Verify API key and secret are correct
- Check headers: `X-API-Key` and `X-API-Secret`
- Ensure tenant is active: `GET /api/admin/tenants/{id}`

### "Class 'YoAPI' not found"
- Ensure `YoAPI.php` exists in `backend/app/Services/`
- Run `composer dump-autoload`

### MikroTik connection fails
- Verify router IP is accessible
- Check API port is enabled (8728)
- Verify username/password
- Ensure firewall allows connection

## Next Steps

1. **Configure YO! Payments**: Update `YOAPI_USERNAME` and `YOAPI_PASSWORD` with real credentials
2. **Set up production database**: Use separate database server for production
3. **Configure SSL**: Set up HTTPS for production deployment
4. **Set up monitoring**: Monitor tenant databases and API usage
5. **Read full documentation**: See `MULTI_TENANCY.md` for complete details

## Production Deployment

See `DEPLOYMENT.md` for complete production deployment instructions.

## Support

- Multi-tenancy guide: `MULTI_TENANCY.md`
- Deployment guide: `DEPLOYMENT.md`
- Backend README: `backend/README.md`
- Data folder: `data/README.md`

---

**Your OnLiFi multi-tenant system is now ready!**

Each tenant can:
✅ Process mobile money payments independently
✅ Manage their own vouchers and routers
✅ Access only their own data
✅ Use the same API without conflicts
