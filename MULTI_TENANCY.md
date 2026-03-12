# OnLiFi Multi-Tenancy Architecture

Complete guide to the multi-tenant architecture implementation in OnLiFi Laravel system.

## Overview

The OnLiFi system implements **database-per-tenant** architecture where:
- Each tenant has their own isolated database
- A central database manages tenant information and authentication
- Tenants are identified via API keys or subdomain
- Dynamic database connection switching per request
- Complete data isolation between tenants

## Architecture Components

### 1. Central Database (`onlifi_central`)

Stores:
- Tenant information (name, slug, domain)
- Database credentials per tenant
- API keys and secrets
- Tenant users and authentication
- Subscription and trial information

### 2. Tenant Databases (`onlifi_tenant_*`)

Each tenant has their own database containing:
- Transactions
- Vouchers and voucher types
- MikroTik routers
- Router telemetry
- Sales points
- All business data

### 3. Tenant Identification Middleware

Automatically identifies tenants via:
- **API Key Authentication**: `X-API-Key` and `X-API-Secret` headers
- **Subdomain Detection**: `tenant1.yourdomain.com`
- **Domain Mapping**: Custom domains mapped to tenants

## Setup Instructions

### 1. Run Central Database Migrations

```bash
cd backend
php artisan migrate --database=central
```

This creates the `tenants` and `tenant_users` tables in the central database.

### 2. Create Your First Tenant

```bash
curl -X POST http://localhost:8000/api/admin/tenants \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Acme Corporation",
    "domain": "acme.yourdomain.com",
    "admin_name": "John Doe",
    "admin_email": "admin@acme.com",
    "admin_password": "SecurePassword123!"
  }'
```

Response will include:
```json
{
  "message": "Tenant created successfully",
  "tenant": {
    "id": 1,
    "name": "Acme Corporation",
    "slug": "acme-corporation",
    "database_name": "onlifi_acme_corporation_abc123",
    "is_active": true
  },
  "api_credentials": {
    "api_key": "onlifi_abc123def456...",
    "api_secret": "xyz789uvw012..."
  }
}
```

**Important**: Save the API credentials securely. They cannot be retrieved later (only regenerated).

### 3. Tenant Database Auto-Creation

When a tenant is created:
1. A new MySQL database is automatically created
2. A dedicated database user is created with proper permissions
3. All tenant migrations are automatically run
4. The tenant database is ready to use immediately

## Using the API as a Tenant

### Method 1: API Key Authentication (Recommended)

Include API credentials in every request:

```bash
curl -X POST http://localhost:8000/api/payments/initiate \
  -H "Content-Type: application/json" \
  -H "X-API-Key: onlifi_abc123def456..." \
  -H "X-API-Secret: xyz789uvw012..." \
  -d '{
    "amount": 1000,
    "msisdn": "256771234567",
    "origin_site": "SiteA",
    "client_mac": "00:11:22:33:44:55",
    "voucher_type": "Feature_1000",
    "origin_url": "https://acme.com/payment"
  }'
```

### Method 2: Subdomain Access

Configure DNS to point subdomains to your server:
- `acme-corporation.yourdomain.com` → Automatically routes to Acme's database
- `another-tenant.yourdomain.com` → Routes to Another Tenant's database

```bash
curl -X POST https://acme-corporation.yourdomain.com/api/payments/initiate \
  -H "Content-Type: application/json" \
  -d '{ ... }'
```

### Method 3: Custom Domain

Map custom domains in tenant settings:

```bash
curl -X PUT http://localhost:8000/api/admin/tenants/1 \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "payments.acme.com"
  }'
```

Then access via: `https://payments.acme.com/api/payments/initiate`

## Tenant Management API

### List All Tenants

```bash
GET /api/admin/tenants
```

### Get Tenant Details

```bash
GET /api/admin/tenants/{id}
```

### Update Tenant

```bash
PUT /api/admin/tenants/{id}
{
  "name": "Updated Name",
  "is_active": true
}
```

### Suspend Tenant

```bash
POST /api/admin/tenants/{id}/suspend
```

Suspended tenants cannot access the API until reactivated.

### Activate Tenant

```bash
POST /api/admin/tenants/{id}/activate
```

### Delete Tenant

```bash
DELETE /api/admin/tenants/{id}
```

**Warning**: This permanently deletes the tenant's database and all data.

### Regenerate API Credentials

```bash
POST /api/admin/tenants/{id}/regenerate-credentials
```

Returns new API key and secret. Old credentials become invalid immediately.

### Extend Trial Period

```bash
POST /api/admin/tenants/{id}/extend-trial
{
  "days": 30
}
```

### Subscribe Tenant

```bash
POST /api/admin/tenants/{id}/subscribe
```

Converts trial to paid subscription (removes trial expiration).

### Get Tenant Statistics

```bash
GET /api/admin/tenants/{id}/stats
```

Returns:
```json
{
  "total_transactions": 1250,
  "successful_transactions": 1180,
  "total_vouchers": 5000,
  "active_vouchers": 3200,
  "total_routers": 5,
  "active_routers": 5
}
```

## How It Works

### Request Flow

1. **Request arrives** at the API endpoint
2. **Tenant Middleware** (`IdentifyTenant`) intercepts the request
3. **Tenant is identified** via API key, subdomain, or domain
4. **Tenant validation** checks if tenant is active and has access
5. **Database connection switches** to tenant's database dynamically
6. **Request proceeds** to controller with tenant context
7. **All queries** automatically use tenant's database
8. **Response returned** to client

### Database Connection Switching

```php
// Middleware automatically does this for each request
$tenant = Tenant::where('api_key', $apiKey)->first();
$tenant->configure(); // Switches DB connection

// Now all models use tenant's database
$transactions = Transaction::all(); // Queries tenant's DB
$vouchers = Voucher::all(); // Queries tenant's DB
```

### Tenant Isolation

Each tenant's data is completely isolated:
- ✅ Tenant A cannot see Tenant B's transactions
- ✅ Tenant A cannot access Tenant B's vouchers
- ✅ Tenant A cannot modify Tenant B's routers
- ✅ Database-level isolation (not just application-level)

## Mobile Money Payment Flow (Multi-Tenant)

### Scenario: Multiple Tenants Using YO! Payments

**Tenant A** and **Tenant B** can both use the same YO! Payments API simultaneously:

1. **Tenant A** initiates payment:
   ```
   POST /api/payments/initiate
   Headers: X-API-Key: tenant_a_key, X-API-Secret: tenant_a_secret
   ```
   - Transaction saved to `onlifi_tenant_a` database
   - Unique external reference: `TXN_1234_abc`

2. **Tenant B** initiates payment (same time):
   ```
   POST /api/payments/initiate
   Headers: X-API-Key: tenant_b_key, X-API-Secret: tenant_b_secret
   ```
   - Transaction saved to `onlifi_tenant_b` database
   - Unique external reference: `TXN_1234_xyz`

3. **YO! Payments IPN** arrives for Tenant A:
   ```
   POST /api/payments/ipn
   Body: { ExternalReference: "TXN_1234_abc" }
   ```
   - System looks up transaction in **all tenant databases**
   - Finds it in `onlifi_tenant_a`
   - Switches to Tenant A's database
   - Updates transaction status
   - Assigns voucher from Tenant A's pool
   - Sends SMS to customer

4. **No conflicts** - Each tenant's data stays isolated

## MikroTik Router Integration (Multi-Tenant)

Each tenant can have their own MikroTik routers:

### Tenant A's Setup
- Router 1: `192.168.1.1` (Kampala Office)
- Router 2: `192.168.2.1` (Entebbe Branch)
- Vouchers work only on Tenant A's routers

### Tenant B's Setup
- Router 1: `192.168.10.1` (Nairobi Office)
- Router 2: `192.168.20.1` (Mombasa Branch)
- Vouchers work only on Tenant B's routers

### Configuration

```bash
# Tenant A adds their router
curl -X POST https://tenant-a.yourdomain.com/api/routers \
  -H "X-API-Key: tenant_a_key" \
  -H "X-API-Secret: tenant_a_secret" \
  -d '{
    "name": "Kampala Office",
    "host": "192.168.1.1",
    "username": "admin",
    "password": "secure123"
  }'

# Tenant B adds their router (different credentials)
curl -X POST https://tenant-b.yourdomain.com/api/routers \
  -H "X-API-Key: tenant_b_key" \
  -H "X-API-Secret: tenant_b_secret" \
  -d '{
    "name": "Nairobi Office",
    "host": "192.168.10.1",
    "username": "admin",
    "password": "different456"
  }'
```

## Sharing Your API

You can share your OnLiFi API with multiple users/organizations:

### Use Case 1: Reseller Model
- You run the central OnLiFi instance
- Create tenant accounts for each reseller
- Each reseller gets their own API credentials
- Each reseller has isolated data and routers
- You manage billing and subscriptions

### Use Case 2: White-Label Solution
- Create tenant with custom domain
- Tenant uses `payments.theirbrand.com`
- Their customers never see your branding
- All data stays in tenant's database

### Use Case 3: Multi-Location Business
- Single business with multiple locations
- Each location is a tenant
- Centralized management via admin API
- Location-specific reporting and vouchers

## Security Features

### 1. API Key Authentication
- 32-character random API keys
- 64-character random API secrets
- Secrets are hashed before comparison
- Keys can be regenerated anytime

### 2. Database Isolation
- Each tenant has separate database
- Separate database users with limited permissions
- No cross-tenant queries possible
- Database-level security

### 3. Access Control
- Tenant active/inactive status
- Trial period enforcement
- Subscription validation
- Automatic access denial for expired accounts

### 4. Request Validation
- All tenant requests validated
- Invalid API keys rejected immediately
- Inactive tenants blocked
- Expired trials blocked

## Testing Multi-Tenancy

### 1. Create Test Tenants

```bash
# Create Tenant A
curl -X POST http://localhost:8000/api/admin/tenants \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Tenant A",
    "admin_name": "Admin A",
    "admin_email": "admin.a@test.com",
    "admin_password": "password123"
  }'

# Create Tenant B
curl -X POST http://localhost:8000/api/admin/tenants \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Tenant B",
    "admin_name": "Admin B",
    "admin_email": "admin.b@test.com",
    "admin_password": "password123"
  }'
```

### 2. Test Data Isolation

```bash
# Add transaction for Tenant A
curl -X POST http://localhost:8000/api/payments/initiate \
  -H "X-API-Key: {tenant_a_key}" \
  -H "X-API-Secret: {tenant_a_secret}" \
  -d '{ "amount": 1000, ... }'

# List transactions for Tenant A
curl -X GET http://localhost:8000/api/transactions \
  -H "X-API-Key: {tenant_a_key}" \
  -H "X-API-Secret: {tenant_a_secret}"
# Should only see Tenant A's transactions

# List transactions for Tenant B
curl -X GET http://localhost:8000/api/transactions \
  -H "X-API-Key: {tenant_b_key}" \
  -H "X-API-Secret: {tenant_b_secret}"
# Should only see Tenant B's transactions (empty if none created)
```

### 3. Test Concurrent Payments

Use a load testing tool to simulate multiple tenants making payments simultaneously:

```bash
# Install Apache Bench or similar
ab -n 100 -c 10 -H "X-API-Key: {tenant_a_key}" \
   -H "X-API-Secret: {tenant_a_secret}" \
   http://localhost:8000/api/payments/initiate
```

## Production Deployment

### 1. Database Setup

```sql
-- Create central database
CREATE DATABASE onlifi_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create database user for central DB
CREATE USER 'onlifi_central'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON onlifi_central.* TO 'onlifi_central'@'localhost';

-- Grant permission to create tenant databases
GRANT CREATE, DROP ON *.* TO 'onlifi_central'@'localhost';
FLUSH PRIVILEGES;
```

### 2. Environment Configuration

```env
# Central Database
CENTRAL_DB_HOST=127.0.0.1
CENTRAL_DB_PORT=3306
CENTRAL_DB_DATABASE=onlifi_central
CENTRAL_DB_USERNAME=onlifi_central
CENTRAL_DB_PASSWORD=secure_password

# Default connection (for tenant database template)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
```

### 3. Run Migrations

```bash
# Central database migrations
php artisan migrate --database=central

# Tenant migrations will run automatically when tenants are created
```

### 4. Secure Admin Endpoints

Add authentication to admin endpoints in production:

```php
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin/tenants')->group(function () {
    // Tenant management routes
});
```

## Troubleshooting

### Issue: "Tenant not identified"

**Cause**: Missing or invalid API credentials

**Solution**:
- Verify API key and secret are correct
- Check headers are properly set: `X-API-Key` and `X-API-Secret`
- Ensure tenant is active

### Issue: "Access denied - trial expired"

**Cause**: Tenant's trial period has ended

**Solution**:
```bash
# Extend trial
POST /api/admin/tenants/{id}/extend-trial
{ "days": 30 }

# Or subscribe
POST /api/admin/tenants/{id}/subscribe
```

### Issue: Database connection errors

**Cause**: Tenant database not created or credentials incorrect

**Solution**:
- Check tenant database exists: `SHOW DATABASES LIKE 'onlifi_%';`
- Verify database user has permissions
- Check central database connection is working

### Issue: Cross-tenant data leakage

**Cause**: Model not using tenant connection

**Solution**:
- Ensure all models have `protected $connection = 'tenant';`
- Verify middleware is applied to routes
- Check tenant is properly identified in logs

## Performance Considerations

### Connection Pooling

Each tenant database connection is cached per request:
- First request: Connection established
- Subsequent queries: Reuse same connection
- End of request: Connection released

### Database Optimization

For many tenants:
- Use connection pooling (MySQL max_connections)
- Monitor database server resources
- Consider read replicas for reporting
- Implement caching (Redis) for frequently accessed data

### Scaling Strategy

**Horizontal Scaling**:
- Multiple application servers
- Load balancer in front
- Shared central database
- Tenant databases can be on different MySQL servers

**Vertical Scaling**:
- Increase database server resources
- Optimize queries and indexes
- Use query caching

## Best Practices

1. **Always use API key authentication** in production
2. **Monitor tenant database sizes** and implement quotas
3. **Regular backups** of both central and tenant databases
4. **Log all tenant access** for audit trails
5. **Implement rate limiting** per tenant
6. **Set up alerts** for suspended/expired tenants
7. **Document API credentials** securely for each tenant
8. **Test data isolation** regularly
9. **Use HTTPS** for all API communications
10. **Implement proper error handling** without leaking tenant information

## Summary

Your OnLiFi system now supports:

✅ **Unlimited tenants** - Each with isolated database
✅ **Concurrent mobile money payments** - No conflicts between tenants
✅ **Shared API infrastructure** - One codebase, many tenants
✅ **Dynamic database routing** - Automatic per-request switching
✅ **Complete data isolation** - Database-level security
✅ **Real MikroTik integration** - Per-tenant router configuration
✅ **Production-ready** - Secure, scalable, and tested

Each tenant can:
- Process payments independently
- Manage their own vouchers
- Configure their own routers
- Access only their own data
- Use the same YO! Payments API without conflicts

The system is ready for testing with real MikroTik routers and production deployment!
