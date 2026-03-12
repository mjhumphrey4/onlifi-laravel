# Authentication System Fix - Summary

## Problem Identified

Your onlifi-vanilla project had **two conflicting authentication systems** running simultaneously:

1. **Old Hardcoded Authentication** (in `api.php`)
   - Used hardcoded `$USERS` array with plaintext passwords
   - Session name: `PAYDASH_SESSION`
   - Stored user data in `$_SESSION['user']`

2. **New Multi-Tenant Authentication** (in `auth_api.php`)
   - Database-driven authentication using `onlifi_central` database
   - Session name: `ONLIFI_SESSION`
   - Stored user data in `$_SESSION['user_id']`, `$_SESSION['username']`, etc.

### Root Cause of 401 Unauthorized Error

The frontend was correctly calling `/api/auth_api.php?action=login` (multi-tenant system), but when it tried to access other endpoints like `/api/api.php?action=stats`, those endpoints were checking for the old session structure (`$_SESSION['user']`) which didn't exist.

## Changes Made

### 1. Updated `newdashboard/api/api.php`

**Removed:**
- Hardcoded `$USERS` array (lines 28-35)
- Old login/logout/me endpoints (lines 120-143)
- Old session name `PAYDASH_SESSION`

**Added:**
- `require_once __DIR__ . '/../../config_multitenant.php';`
- Updated session name to `ONLIFI_SESSION`
- Updated `requireAuth()` to use multi-tenant session structure:
  ```php
  function requireAuth() {
      if (empty($_SESSION['user_id'])) fail('Unauthorized', 401);
      return [
          'id' => $_SESSION['user_id'],
          'username' => $_SESSION['username'] ?? '',
          'email' => $_SESSION['email'] ?? '',
          'name' => $_SESSION['full_name'] ?? '',
          'role' => $_SESSION['role'] ?? 'user',
          'database_name' => $_SESSION['database_name'] ?? ''
      ];
  }
  ```

### 2. Updated `newdashboard/api/mikrotik_api.php`

**Changed:**
- Session name from `PAYDASH_SESSION` to `ONLIFI_SESSION`
- Config include from `config.php` to `config_multitenant.php`
- Updated `requireAuth()` to use multi-tenant session structure
- Updated session lifetime to use `SESSION_LIFETIME` constant

### 3. Authentication Flow Now Works As:

```
User Login → auth_api.php (login) → Creates session with:
  - $_SESSION['user_id']
  - $_SESSION['username']
  - $_SESSION['email']
  - $_SESSION['full_name']
  - $_SESSION['role']
  - $_SESSION['database_name']

User Access Stats → api.php (stats) → Checks $_SESSION['user_id'] ✓
User Access Transactions → api.php (transactions) → Checks $_SESSION['user_id'] ✓
User Access Mikrotik → mikrotik_api.php → Checks $_SESSION['user_id'] ✓
```

## Files Modified

1. `c:\Users\josep\Documents\vultr-mdtk\Markdown\onlifi-vanilla\newdashboard\api\api.php`
2. `c:\Users\josep\Documents\vultr-mdtk\Markdown\onlifi-vanilla\newdashboard\api\mikrotik_api.php`

## Files NOT Modified (Legacy/Separate Systems)

- `admin-dashboard/config/auth.php` - Legacy admin dashboard (separate system)
- `withdraw/index.php` - Withdrawal system (uses old auth)
- `withdraw/reset_passwords.php` - Withdrawal system (uses old auth)

**Note:** The `admin-dashboard` and `withdraw` folders appear to be legacy systems that are separate from the new multi-tenant dashboard. They still use the old `PAYDASH_SESSION` authentication. If you need these integrated with the multi-tenant system, they would need similar updates.

## Testing Steps

1. **Clear browser cookies/session** to ensure clean state
2. **Navigate to:** `http://192.168.0.180/signup`
3. **Create a new account** with the multi-tenant signup form
4. **Login** with the new credentials
5. **Verify** that you can access:
   - Dashboard stats
   - Transactions
   - Withdrawals
   - Performance data

## Expected Behavior

✅ Login with valid credentials from `onlifi_central.users` table → Success  
✅ Access `/api/api.php?action=stats` → Returns data  
✅ Access `/api/api.php?action=transactions` → Returns data  
✅ Access `/api/mikrotik_api.php?action=clients` → Returns data  
❌ Login with invalid credentials → 401 Unauthorized  
❌ Access protected endpoints without login → 401 Unauthorized  

## Additional Errors Found

### 1. Site-Based Access Control Issue

The old system used a "site" concept where users were assigned to specific sites (Enock, Richard, STK, Remmy, Guma). The multi-tenant system doesn't use this concept - each user has their own isolated database.

**Current Behavior:**
- Admin users can see all sites
- Regular users see no sites (empty array)

**Recommendation:** 
- If you want to keep the site-based system for admin users, you'll need to create a mapping between the multi-tenant users and the old site databases
- OR migrate completely to the multi-tenant model where each user manages their own routers/vouchers in their tenant database

### 2. Database Connection in api.php

The `api.php` still uses the old `getDb()` function which connects to specific databases by name (payment_mikrotik, remmy_mikrotik, etc.). This is incompatible with the multi-tenant model where each user has their own database.

**Recommendation:**
- Update the stats/transactions/withdrawals endpoints to use the user's tenant database from `$_SESSION['database_name']`
- OR keep the current multi-database approach for backward compatibility with existing data

## Next Steps

1. **Test the login flow** with a user from the `onlifi_central.users` table
2. **Decide on database architecture:**
   - Option A: Full multi-tenant (each user has isolated database)
   - Option B: Hybrid (multi-tenant auth + shared site databases for existing users)
3. **Update the site-based endpoints** if needed
4. **Remove or update legacy systems** (admin-dashboard, withdraw folders)
5. **Update documentation** to reflect the new authentication system

## Security Improvements

✅ Removed hardcoded passwords from source code  
✅ Using bcrypt password hashing (cost 12)  
✅ Session security with httponly, secure, samesite flags  
✅ Database-driven user management  
✅ Activity logging for user actions  
✅ Session token tracking  

## Configuration

All authentication settings are now centralized in:
- `config_multitenant.php` - Multi-tenant configuration
- Central database: `onlifi_central`
- Session name: `ONLIFI_SESSION`
- Session lifetime: 24 hours (86400 seconds)
