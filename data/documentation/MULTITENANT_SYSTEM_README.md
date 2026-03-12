# Multi-Tenant System Documentation

## Overview

The Onlifi-Vanilla application has been upgraded to a **multi-tenant system** where each user gets their own isolated database upon signup. This ensures complete data separation and security between users while maintaining centralized authentication.

## Architecture

### Central Authentication Database (`onlifi_central`)
Stores all user credentials, sessions, and manages multi-tenant access.

### Tenant Databases (`onlifi_username_xxxxxx`)
Each user gets a unique database containing their:
- MikroTik router configurations
- Vouchers and voucher groups
- Active clients data
- Router telemetry
- Transaction history
- All FreeRADIUS data

## Key Features

### ✅ User Signup System
- Beautiful, modern signup page with real-time validation
- Password strength indicator
- Automatic database provisioning
- Email verification ready
- Phone number support

### ✅ Centralized User Management
- Admin dashboard to view all users
- User status management (active/suspended/pending)
- Role-based access control (admin/user/reseller)
- Activity logging
- Session management

### ✅ Database Isolation
- Each user gets their own MySQL database
- Complete data separation
- Automatic schema deployment
- Secure database naming convention

### ✅ Enhanced Security
- Bcrypt password hashing (cost 12)
- Session token management
- IP and user agent tracking
- Login attempt monitoring
- Password strength requirements

## Installation & Setup

### 1. Create Central Database

```bash
mysql -u root -p < database/central_auth_schema.sql
```

This creates the `onlifi_central` database with all authentication tables.

### 2. Update Configuration

Edit `config_multitenant.php` with your database credentials:

```php
define('CENTRAL_DB_HOST', 'localhost');
define('CENTRAL_DB_NAME', 'onlifi_central');
define('CENTRAL_DB_USER', 'yo');
define('CENTRAL_DB_PASS', 'password');

define('TENANT_DB_HOST', 'localhost');
define('TENANT_DB_USER', 'yo');
define('TENANT_DB_PASS', 'password');
```

### 3. Grant Database Privileges

The database user needs permission to create databases:

```sql
GRANT ALL PRIVILEGES ON *.* TO 'yo'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

### 4. Build Frontend

```bash
cd newdashboard
npm install
npm run build
```

### 5. Test the System

1. Navigate to `/signup` to create a new account
2. Login with your credentials
3. Admin can view all users at `/users`

## User Signup Flow

### Step 1: User Registration
User fills out the signup form:
- Full name
- Username (alphanumeric + underscore)
- Email address
- Phone number (optional)
- Password (with strength validation)

### Step 2: Validation
- Username uniqueness check
- Email format validation
- Password strength requirements:
  - Minimum 8 characters
  - At least one uppercase letter
  - At least one lowercase letter
  - At least one number

### Step 3: Database Provisioning
1. User record created in `onlifi_central.users`
2. Unique database name generated: `onlifi_username_xxxxxx`
3. Database created automatically
4. MikroTik schema imported to new database
5. Default user settings created
6. Free tier subscription assigned

### Step 4: Confirmation
- Success message displayed
- User redirected to login page
- Can immediately login with credentials

## API Endpoints

### Authentication API (`/api/auth_api.php`)

#### Signup
```
POST /api/auth_api.php?action=signup
{
  "username": "johndoe",
  "email": "john@example.com",
  "password": "SecurePass123",
  "full_name": "John Doe",
  "phone": "+256700000000"
}
```

#### Login
```
POST /api/auth_api.php?action=login
{
  "username": "johndoe",
  "password": "SecurePass123"
}
```

#### Get Current User
```
GET /api/auth_api.php?action=me
```

#### Logout
```
POST /api/auth_api.php?action=logout
```

#### List All Users (Admin Only)
```
GET /api/auth_api.php?action=users
```

#### Update User Status (Admin Only)
```
POST /api/auth_api.php?action=update_user_status
{
  "user_id": 5,
  "status": "suspended"
}
```

#### Update Profile
```
POST /api/auth_api.php?action=update_profile
{
  "full_name": "John Smith",
  "email": "john.smith@example.com",
  "phone": "+256700000001"
}
```

#### Change Password
```
POST /api/auth_api.php?action=change_password
{
  "current_password": "OldPass123",
  "new_password": "NewPass456"
}
```

#### User Statistics (Admin Only)
```
GET /api/auth_api.php?action=user_stats
```

## Database Schema

### Central Database Tables

#### `users`
- User credentials and profile information
- Role and status management
- Database name mapping

#### `user_sessions`
- Active session tracking
- Session tokens
- IP and user agent logging

#### `user_activity_log`
- Audit trail of user actions
- Login/logout tracking
- Administrative actions

#### `database_provisioning_log`
- Database creation tracking
- Success/failure logging
- Error messages

#### `user_settings`
- Theme preferences
- Language settings
- Notification preferences
- Two-factor authentication settings

#### `user_subscriptions`
- Plan type (free/basic/premium/enterprise)
- Resource limits (routers, vouchers, clients)
- Subscription status

#### `email_verification_tokens`
- Email verification workflow
- Token expiration

#### `password_reset_tokens`
- Password reset workflow
- Token expiration

## User Roles

### Admin
- Full system access
- Can view all users
- Can suspend/activate users
- Access to user management page
- Can view system statistics

### User (Default)
- Access to own dashboard
- Manage own routers and vouchers
- View own clients and devices
- Limited to own database

### Reseller
- Can create sub-accounts (future feature)
- Bulk voucher management
- Sales reporting

## Security Features

### Password Requirements
- Minimum 8 characters
- At least one uppercase letter
- At least one lowercase letter
- At least one number
- Bcrypt hashing with cost 12

### Session Management
- 24-hour session lifetime
- Secure cookies (httponly, samesite)
- Session token tracking
- IP address logging

### Activity Logging
- All login attempts
- User creation
- Status changes
- Profile updates
- Password changes

### Database Security
- Prepared statements (PDO)
- SQL injection prevention
- Database name sanitization
- Connection pooling

## Admin Features

### User Management Dashboard
Located at `/users` (admin only)

**Features:**
- View all registered users
- Search by username, email, or name
- Filter by status (active/suspended/pending)
- Filter by role (admin/user/reseller)
- Suspend or activate users
- View user statistics
- See database assignments
- Track last login times

**Statistics:**
- Total users
- Active users
- Suspended users
- Administrators count
- Recent signups (30 days)
- Active sessions

## Subscription Plans

### Free Tier (Default)
- Max 2 routers
- Max 500 vouchers
- Max 100 clients
- Basic support

### Basic Tier
- Max 5 routers
- Max 2,000 vouchers
- Max 500 clients
- Email support

### Premium Tier
- Max 20 routers
- Max 10,000 vouchers
- Max 2,000 clients
- Priority support

### Enterprise Tier
- Unlimited routers
- Unlimited vouchers
- Unlimited clients
- 24/7 support
- Custom features

## Migration from Old System

### For Existing Users

1. **Create user account** in central database:
```sql
INSERT INTO onlifi_central.users (username, email, password_hash, full_name, role, database_name, status)
VALUES ('existing_user', 'user@example.com', '$2y$10$...', 'User Name', 'user', 'payment_mikrotik', 'active');
```

2. **Point to existing database** instead of creating new one

3. **Create user settings**:
```sql
INSERT INTO onlifi_central.user_settings (user_id) VALUES (LAST_INSERT_ID());
```

4. **Create subscription**:
```sql
INSERT INTO onlifi_central.user_subscriptions (user_id, plan_type) VALUES (LAST_INSERT_ID(), 'free');
```

## Troubleshooting

### Database Creation Fails

**Issue:** User created but database provisioning failed

**Solution:**
1. Check MySQL user has CREATE DATABASE privilege
2. Check disk space
3. Verify schema file exists: `database/mikrotik_schema.sql`
4. Check error in `database_provisioning_log` table

### Cannot Login After Signup

**Issue:** Credentials not working

**Solution:**
1. Check user status is 'active' in users table
2. Verify password was hashed correctly
3. Check session configuration
4. Clear browser cookies

### Admin Cannot See Users Page

**Issue:** 404 or access denied

**Solution:**
1. Verify user role is 'admin' in database
2. Check route is configured: `/users`
3. Rebuild frontend: `npm run build`
4. Clear browser cache

### Database Connection Errors

**Issue:** "Database connection failed"

**Solution:**
1. Verify credentials in `config_multitenant.php`
2. Check MySQL service is running
3. Test connection: `mysql -u yo -p`
4. Check error logs: `tail -f /var/log/apache2/error.log`

## Best Practices

### For Administrators

1. **Regular Backups**
   - Backup central database daily
   - Backup tenant databases weekly
   - Test restore procedures

2. **Monitor Activity**
   - Review activity logs regularly
   - Check for suspicious login attempts
   - Monitor database growth

3. **User Management**
   - Verify new user emails
   - Suspend inactive accounts
   - Clean up old sessions

### For Developers

1. **Database Access**
   - Always use `getCentralDB()` for auth
   - Use `getTenantDB($dbName)` for user data
   - Never hardcode database names

2. **Security**
   - Validate all inputs
   - Use prepared statements
   - Log security events
   - Implement rate limiting

3. **Error Handling**
   - Log errors, don't display them
   - Return generic error messages
   - Monitor error logs

## Future Enhancements

- [ ] Email verification workflow
- [ ] Password reset functionality
- [ ] Two-factor authentication
- [ ] OAuth integration (Google, Facebook)
- [ ] Subscription billing integration
- [ ] User dashboard customization
- [ ] Multi-language support
- [ ] API rate limiting
- [ ] Advanced analytics
- [ ] Automated backups per tenant

## Support

For issues or questions:
1. Check error logs
2. Review activity logs
3. Verify database connections
4. Check user permissions

## License

Proprietary - All rights reserved
