# OnLiFi Super Admin Panel Guide

Complete guide for the Super Admin Panel with tenant approval workflow.

## Overview

The OnLiFi system now includes a **complete Super Admin Panel** that allows you to:

- ✅ **Approve/reject tenant signups** before granting access
- ✅ **Manage all tenants** from a central dashboard
- ✅ **Create system announcements** for all or specific tenants
- ✅ **Configure global settings** (trial periods, auto-approval, etc.)
- ✅ **View tenant databases** and run read-only queries
- ✅ **Monitor system statistics** and tenant activity

---

## Architecture

### Approval Workflow

```
User Signs Up
    ↓
Tenant record created with status='pending'
    ↓
NO database created yet (saves resources)
    ↓
Super Admin reviews in admin panel
    ↓
Admin approves or rejects
    ↓
If APPROVED:
  - Status changed to 'approved'
  - Database created automatically
  - Migrations run automatically
  - API credentials activated
  - Trial period starts
  - User notified (future feature)
    ↓
Tenant gets full access

If REJECTED:
  - Status changed to 'rejected'
  - Rejection reason stored
  - No database created
  - User notified with reason (future feature)
```

### Database Structure

**Central Database (`onlifi_central`):**
- `super_admins` - Admin user accounts
- `tenants` - All tenant records (pending, approved, rejected)
- `tenant_users` - Tenant user accounts
- `announcements` - System announcements
- `system_settings` - Global configuration

**Tenant Databases (`onlifi_tenant_*`):**
- Only created AFTER approval
- Contains all business data (transactions, vouchers, routers, etc.)
- Completely isolated per tenant

---

## Setup Instructions

### 1. Run Migrations

```bash
cd backend

# Run central database migrations (includes new admin tables)
php artisan migrate --database=central
```

This creates:
- `super_admins` table
- `announcements` table
- `system_settings` table
- Updates `tenants` table with approval fields

### 2. Create First Super Admin

```bash
php artisan db:seed --class=SuperAdminSeeder
```

**Default credentials:**
- Email: `admin@onlifi.com`
- Password: `admin123`

⚠️ **IMPORTANT**: Change this password immediately after first login!

### 3. Configure System Settings

The following settings are auto-created:

| Setting | Default | Description |
|---------|---------|-------------|
| `auto_approve_tenants` | `false` | Auto-approve new signups (bypass manual approval) |
| `default_trial_days` | `30` | Trial period length for new tenants |
| `allow_tenant_signup` | `true` | Allow new tenant registrations |
| `maintenance_mode` | `false` | Put system in maintenance mode |

---

## Using the Admin Panel

### Access the Admin Panel

**Frontend URL:** `http://localhost:5173/admin/login`

**Backend API Base:** `http://localhost:8000/api/super-admin`

### 1. Login

```bash
POST /api/super-admin/login
Content-Type: application/json

{
  "email": "admin@onlifi.com",
  "password": "admin123"
}
```

**Response:**
```json
{
  "message": "Login successful",
  "admin": {
    "id": 1,
    "name": "Super Administrator",
    "email": "admin@onlifi.com",
    "role": "super_admin"
  },
  "token": "1|abc123..."
}
```

Save the token for subsequent requests.

### 2. View Dashboard

The dashboard shows:
- Total tenants
- Pending approvals (highlighted)
- Active tenants
- Suspended tenants
- Trial vs subscribed breakdown
- Expired trials needing attention

### 3. Approve Pending Tenants

**View pending tenants:**
```bash
GET /api/super-admin/tenants/pending
Authorization: Bearer {token}
```

**Approve a tenant:**
```bash
POST /api/super-admin/tenants/{id}/approve
Authorization: Bearer {token}
```

This automatically:
1. Changes status to 'approved'
2. Creates tenant database
3. Runs all migrations
4. Activates API credentials
5. Sets trial end date

**Reject a tenant:**
```bash
POST /api/super-admin/tenants/{id}/reject
Authorization: Bearer {token}
Content-Type: application/json

{
  "reason": "Incomplete business information provided"
}
```

### 4. Manage Announcements

**Create announcement:**
```bash
POST /api/super-admin/announcements
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "System Maintenance Scheduled",
  "content": "We will be performing maintenance on March 15th from 2-4 AM UTC",
  "type": "warning",
  "target": "all",
  "is_active": true,
  "starts_at": "2024-03-14T00:00:00Z",
  "ends_at": "2024-03-16T00:00:00Z"
}
```

**Types:**
- `info` - General information
- `warning` - Important warnings
- `success` - Positive updates
- `error` - Critical alerts

**Targets:**
- `all` - All tenants
- `active` - Only active tenants
- `trial` - Only trial tenants
- `specific` - Specific tenant IDs (provide `tenant_ids` array)

### 5. Configure System Settings

**View all settings:**
```bash
GET /api/super-admin/settings
Authorization: Bearer {token}
```

**Update a setting:**
```bash
PUT /api/super-admin/settings/auto_approve_tenants
Authorization: Bearer {token}
Content-Type: application/json

{
  "value": "true",
  "type": "boolean"
}
```

**Bulk update:**
```bash
POST /api/super-admin/settings/bulk-update
Authorization: Bearer {token}
Content-Type: application/json

{
  "settings": [
    { "key": "default_trial_days", "value": "60" },
    { "key": "auto_approve_tenants", "value": "false" }
  ]
}
```

### 6. View Tenant Database

**List tables:**
```bash
GET /api/super-admin/tenants/{id}/database
Authorization: Bearer {token}
```

**Response:**
```json
{
  "database_name": "onlifi_tenant_abc123",
  "tables": [
    { "name": "transactions", "row_count": 1250 },
    { "name": "vouchers", "row_count": 5000 },
    { "name": "radcheck", "row_count": 5000 },
    { "name": "radreply", "row_count": 5000 }
  ]
}
```

**Run read-only query:**
```bash
POST /api/super-admin/tenants/{id}/database/query
Authorization: Bearer {token}
Content-Type: application/json

{
  "query": "SELECT COUNT(*) as total FROM transactions WHERE status='success'"
}
```

⚠️ **Security:** Only SELECT queries are allowed. INSERT/UPDATE/DELETE are blocked.

---

## Tenant Signup Flow

### Without Auto-Approval (Default)

1. **User signs up:**
```bash
POST /api/tenant/signup
Content-Type: application/json

{
  "name": "Acme Corporation",
  "admin_name": "John Doe",
  "admin_email": "john@acme.com",
  "admin_password": "SecurePass123!",
  "domain": "acme.yourdomain.com"
}
```

2. **Response:**
```json
{
  "message": "Tenant created successfully",
  "tenant": {
    "id": 5,
    "name": "Acme Corporation",
    "status": "pending",
    "is_active": false
  },
  "note": "Your application is pending admin approval. You will be notified once approved."
}
```

3. **Admin reviews** in admin panel

4. **Admin approves** → Database created, tenant gets access

5. **Tenant can now login** and use API with credentials

### With Auto-Approval Enabled

1. **Admin enables auto-approval:**
```bash
PUT /api/super-admin/settings/auto_approve_tenants
{
  "value": "true",
  "type": "boolean"
}
```

2. **User signs up** (same as above)

3. **Instant approval:**
```json
{
  "message": "Tenant created successfully",
  "tenant": {
    "id": 5,
    "name": "Acme Corporation",
    "status": "approved",
    "is_active": true
  },
  "api_credentials": {
    "api_key": "onlifi_xyz789...",
    "api_secret": "abc123..."
  },
  "note": "Your account is active. You can start using the API immediately."
}
```

4. **Database created automatically**

5. **Tenant has immediate access**

---

## Admin Panel Features

### Dashboard
- Real-time statistics
- Pending approval count (highlighted)
- Active/suspended tenant breakdown
- Trial vs subscription metrics
- Expiring trials alert
- Quick access to key functions

### Tenant Management
- List all tenants (with filters)
- View tenant details
- Approve/reject pending signups
- Suspend/activate tenants
- Extend trial periods
- Convert trial to subscription
- Regenerate API credentials
- Delete tenants (with database cleanup)
- View tenant statistics
- Access tenant database

### Announcements
- Create system-wide announcements
- Target specific tenant groups
- Schedule announcements (start/end dates)
- Different types (info, warning, success, error)
- Active/inactive toggle
- Edit/delete announcements

### System Settings
- Configure trial period length
- Enable/disable auto-approval
- Enable/disable new signups
- Maintenance mode toggle
- Custom settings per group
- Bulk update settings

### Database Viewer
- View tenant database structure
- See table row counts
- Run read-only SELECT queries
- Export query results
- Monitor database health

---

## Security Features

### Authentication
- Sanctum token-based authentication
- Separate admin login from tenant login
- Token expiration and refresh
- Password change functionality

### Authorization
- Role-based access control (RBAC)
- Super admin vs regular admin roles
- Protected admin routes
- Middleware validation

### Data Protection
- Admin actions logged (future feature)
- Read-only database queries
- Tenant data isolation
- Secure credential storage

---

## API Endpoints Reference

### Authentication
```
POST   /api/super-admin/login
POST   /api/super-admin/logout
GET    /api/super-admin/me
POST   /api/super-admin/change-password
```

### Tenant Management
```
GET    /api/super-admin/tenants
GET    /api/super-admin/tenants/pending
GET    /api/super-admin/tenants/statistics
GET    /api/super-admin/tenants/recent-activity
GET    /api/super-admin/tenants/{id}
PUT    /api/super-admin/tenants/{id}
DELETE /api/super-admin/tenants/{id}
POST   /api/super-admin/tenants/{id}/approve
POST   /api/super-admin/tenants/{id}/reject
POST   /api/super-admin/tenants/{id}/suspend
POST   /api/super-admin/tenants/{id}/activate
POST   /api/super-admin/tenants/{id}/regenerate-credentials
POST   /api/super-admin/tenants/{id}/extend-trial
POST   /api/super-admin/tenants/{id}/subscribe
GET    /api/super-admin/tenants/{id}/stats
GET    /api/super-admin/tenants/{id}/database
POST   /api/super-admin/tenants/{id}/database/query
```

### Announcements
```
GET    /api/super-admin/announcements
POST   /api/super-admin/announcements
GET    /api/super-admin/announcements/{id}
PUT    /api/super-admin/announcements/{id}
DELETE /api/super-admin/announcements/{id}
```

### System Settings
```
GET    /api/super-admin/settings
GET    /api/super-admin/settings/groups
GET    /api/super-admin/settings/group/{group}
POST   /api/super-admin/settings
GET    /api/super-admin/settings/{key}
PUT    /api/super-admin/settings/{key}
DELETE /api/super-admin/settings/{key}
POST   /api/super-admin/settings/bulk-update
```

### Public Endpoints
```
POST   /api/tenant/signup
GET    /api/system/settings/public
```

---

## Best Practices

### For Super Admins

1. **Review signups promptly** - Don't leave tenants waiting
2. **Provide clear rejection reasons** - Help users understand what's needed
3. **Monitor trial expirations** - Reach out before trials end
4. **Use announcements wisely** - Don't spam tenants
5. **Backup regularly** - Both central and tenant databases
6. **Audit admin actions** - Review who approved/rejected what
7. **Secure admin credentials** - Use strong passwords, enable 2FA (future)

### For System Configuration

1. **Set appropriate trial periods** - Balance between testing and conversion
2. **Consider auto-approval** - For low-risk scenarios only
3. **Schedule maintenance announcements** - Give advance notice
4. **Monitor database growth** - Set up alerts for large tenants
5. **Regular security updates** - Keep system patched
6. **Test approval workflow** - Ensure smooth tenant onboarding

---

## Troubleshooting

### Issue: Can't login to admin panel

**Check:**
- Super admin account exists in database
- Credentials are correct
- Token is being saved in localStorage
- API endpoint is accessible

**Solution:**
```bash
# Create admin if missing
php artisan db:seed --class=SuperAdminSeeder

# Reset password
php artisan tinker
>>> $admin = App\Models\SuperAdmin::where('email', 'admin@onlifi.com')->first();
>>> $admin->password = Hash::make('newpassword');
>>> $admin->save();
```

### Issue: Approval fails

**Check:**
- Database permissions for creating new databases
- MySQL user has CREATE DATABASE privilege
- Sufficient disk space
- Migration files exist in tenant folder

**Solution:**
```sql
GRANT CREATE ON *.* TO 'onlifi_central'@'localhost';
FLUSH PRIVILEGES;
```

### Issue: Tenant can't access after approval

**Check:**
- Tenant status is 'approved'
- is_active is true
- Database was created successfully
- API credentials are correct

**Solution:**
```bash
# Check tenant status
php artisan tinker
>>> $tenant = App\Models\Tenant::find(1);
>>> $tenant->status; // Should be 'approved'
>>> $tenant->is_active; // Should be true
>>> $tenant->database_name; // Should exist
```

---

## Summary

Your OnLiFi system now has a **complete Super Admin Panel** with:

✅ **Tenant approval workflow** - Manual or automatic
✅ **Dashboard with statistics** - Real-time monitoring
✅ **Announcement system** - Communicate with tenants
✅ **Global settings management** - Configure system behavior
✅ **Database viewer** - Inspect tenant data
✅ **Secure authentication** - Token-based with RBAC
✅ **Resource optimization** - Databases only created when approved

**The admin panel is production-ready and fully functional!**
