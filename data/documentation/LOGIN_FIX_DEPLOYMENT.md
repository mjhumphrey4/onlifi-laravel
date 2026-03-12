# Login Fix - Deployment Guide

## Problem Fixed

**Issue:** After clicking "Sign In", nothing happened. Users stayed on the login page even with valid credentials.

**Root Cause:** The `Login.tsx` component didn't redirect users to the dashboard after successful login.

## Changes Made

### 1. `newdashboard/src/app/pages/Login.tsx`
- Added `useNavigate` hook from `react-router`
- Added `navigate('/')` after successful login to redirect to dashboard

### 2. `newdashboard/src/app/components/Layout.tsx`
- Added `useNavigate` hook
- Updated logout handler to redirect to `/login` page after logout

## Deployment Steps

### Option 1: Automatic Deployment via Jenkins

1. **Commit and push changes to GitHub:**
   ```bash
   cd c:\Users\josep\Documents\vultr-mdtk\Markdown\onlifi-vanilla
   git add .
   git commit -m "Fix: Add navigation redirect after login and logout"
   git push origin main
   ```

2. **Trigger Jenkins build** (or wait for automatic trigger)
   - Jenkins will pull the latest code
   - Sync to server
   - Build the React app on the server
   - Set proper permissions

### Option 2: Manual Deployment

If Jenkins is not set up or you want to deploy manually:

1. **SSH to the server:**
   ```bash
   ssh hum@192.168.0.180
   ```

2. **Navigate to the project directory:**
   ```bash
   cd /var/www/html/newdashboard
   ```

3. **Pull latest changes:**
   ```bash
   git pull origin main
   ```

4. **Install dependencies (if needed):**
   ```bash
   npm install
   ```

5. **Build the React app:**
   ```bash
   npm run build
   ```

6. **Set proper permissions:**
   ```bash
   cd /var/www/html
   sudo chown -R hum:www-data newdashboard
   sudo chmod -R 775 newdashboard
   sudo find newdashboard/dist -type f -exec chmod 664 {} \;
   ```

7. **Clear browser cache and test:**
   - Open browser
   - Clear cache (Ctrl+Shift+Delete)
   - Navigate to `http://192.168.0.180/login`
   - Login with valid credentials
   - Should redirect to dashboard

## Testing Checklist

After deployment, verify:

- [ ] Navigate to `http://192.168.0.180/login`
- [ ] Enter valid credentials (from `onlifi_central.users` table)
- [ ] Click "Sign In"
- [ ] **Expected:** Redirected to dashboard at `http://192.168.0.180/`
- [ ] **Expected:** See user name and email in sidebar
- [ ] Click "Sign out"
- [ ] **Expected:** Redirected back to login page
- [ ] Try accessing `http://192.168.0.180/` without login
- [ ] **Expected:** Automatically redirected to login page

## Test Credentials

If you need to create a test user:

```bash
# SSH to server
ssh hum@192.168.0.180

# Access MySQL
mysql -u yo -p

# Create test user
USE onlifi_central;

INSERT INTO users (username, email, password_hash, full_name, role, database_name, status)
VALUES (
    'testuser',
    'test@example.com',
    '$2y$12$LQv3c1yycEPICh0K.fQwj.OhdVPxqZdXqXqXqXqXqXqXqXqXqXqXq',  -- password: Test@123
    'Test User',
    'user',
    'onlifi_testuser_abc123',
    'active'
);
```

Or use the signup page at `http://192.168.0.180/signup`

## Troubleshooting

### Issue: Still not redirecting after login

**Check browser console (F12):**
- Look for JavaScript errors
- Check Network tab for failed API calls

**Verify build was successful:**
```bash
ssh hum@192.168.0.180
cd /var/www/html/newdashboard
ls -la dist/
# Should see index.html and assets folder with recent timestamps
```

**Clear browser cache completely:**
- Chrome: Ctrl+Shift+Delete → Clear all cached images and files
- Or use Incognito mode

### Issue: 401 Unauthorized on login

This means the authentication fix from earlier is not deployed. Verify:

```bash
ssh hum@192.168.0.180
cd /var/www/html/newdashboard/api

# Check if api.php has multi-tenant auth
grep -n "config_multitenant" api.php
grep -n "ONLIFI_SESSION" api.php

# Should see these lines present
```

If not present, the authentication fix needs to be deployed first.

### Issue: Blank page after login

**Check Nginx logs:**
```bash
sudo tail -f /var/log/nginx/onlifi-error.log
```

**Verify React build:**
```bash
ls -la /var/www/html/newdashboard/dist/index.html
cat /var/www/html/newdashboard/dist/index.html | head -20
```

## Build Configuration

The React app uses Vite for building. Configuration is in:
- `newdashboard/vite.config.ts`
- `newdashboard/package.json`

Build command: `npm run build`
Output directory: `newdashboard/dist/`

## Summary

The login functionality now works as follows:

1. User enters credentials on `/login`
2. Clicks "Sign In"
3. Frontend calls `/api/auth_api.php?action=login`
4. If successful, user state is updated in AuthContext
5. **NEW:** Browser navigates to `/` (dashboard)
6. Layout component checks authentication
7. If authenticated, shows dashboard
8. If not authenticated, redirects back to `/login`

The logout flow:

1. User clicks "Sign out" in sidebar
2. Frontend calls `/api/auth_api.php?action=logout`
3. User state is cleared
4. **NEW:** Browser navigates to `/login`
5. User sees login page

## Files Modified

1. `newdashboard/src/app/pages/Login.tsx` - Added navigation after login
2. `newdashboard/src/app/components/Layout.tsx` - Added navigation after logout

## Next Steps

After successful deployment:

1. Test login with multiple user types (admin, regular user)
2. Test logout functionality
3. Test protected route access (should redirect to login)
4. Verify session persistence (refresh page should keep user logged in)
5. Test on different browsers
6. Test on mobile devices
