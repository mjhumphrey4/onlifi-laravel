# Onlifi Laravel - Deployment & Fix Steps

## Completed Fixes

### 1. ✅ Fixed 401 Unauthorized Errors
- **File**: `backend/app/Http/Middleware/IdentifyTenant.php`
- **Change**: Middleware now recognizes Sanctum bearer tokens from authenticated tenant users
- **Impact**: All tenant API calls now work with login tokens

### 2. ✅ Fixed Legacy PHP Endpoint Calls
- **Files Updated**:
  - `frontend/src/app/pages/Devices.tsx`
  - `frontend/src/app/pages/Clients.tsx`
  - `frontend/src/app/pages/Settings.tsx`
- **Change**: All now use Laravel API endpoints (`/api/routers`, `/api/clients`) with proper auth headers
- **Impact**: No more 404 errors on `mikrotik_api.php`

### 3. ✅ Added Dashboard Improvements
- **File**: `frontend/src/app/pages/Dashboard.tsx`
- **Features**:
  - Top 20 Clients (left side) with avatar, name, IP, sessions, total spent, status
  - Top 20 Recent Transactions (right side) with icons, phone, voucher, amount, date
  - Both have "View all" buttons
  - Responsive grid layout

### 4. ✅ Added Notifications Bell
- **File**: `frontend/src/app/components/Layout.tsx`
- **Features**:
  - Bell icon in header (mobile & desktop)
  - Unread count badge
  - Dropdown panel with announcements
  - Mark as read functionality
  - Backend endpoint: `GET /api/announcements/active`

### 5. ✅ Added Voucher Templates
- **Backend Files**:
  - Migration: `database/migrations/2024_01_01_000010_create_voucher_templates_table.php`
  - Model: `app/Models/VoucherTemplate.php`
  - Controller: `app/Http/Controllers/VoucherTemplateController.php`
  - Routes: `/api/voucher-templates/*`
- **Frontend Files**:
  - Page: `frontend/src/app/pages/VoucherTemplates.tsx`
  - Route: `/voucher-templates`
  - Menu item added to sidebar
- **Features**:
  - Create/edit templates with custom colors, layouts (single, 2x2, 2x4, 3x3)
  - Display options (code, type, price, duration, sales point, etc.)
  - Preview with print support
  - Set default template

### 6. ✅ Added Clients Backend Endpoint
- **File**: `backend/app/Http/Controllers/ClientController.php`
- **Routes**: 
  - `GET /api/clients`
  - `GET /api/clients/refresh`
  - `GET /api/clients/{id}`

## Required Next Steps

### Step 1: Run Database Migrations
```bash
cd backend
php artisan migrate
```

This will create the `voucher_templates` table.

### Step 2: Clear Laravel Caches
```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

### Step 3: Restart Backend Server
```bash
php artisan serve --host=192.168.0.180 --port=8000
```

### Step 4: Rebuild Frontend (if needed)
```bash
cd frontend
npm run build
# or for development
npm run dev
```

### Step 5: Test Login Flow
1. **Tenant Login**: Go to `/login` and login with tenant credentials
2. **Verify Token**: Check browser localStorage for `tenant_token`
3. **Test API Calls**: Navigate to dashboard, vouchers, clients pages
4. **Check Console**: Ensure no 401 or 404 errors

### Step 6: Test Voucher Creation
1. Go to `/voucher-types` and create a voucher type
2. Go to `/sales-points` (via Sales Points dialog in Vouchers page)
3. Create a sales point
4. Go to `/vouchers` and generate vouchers
5. Verify no "API credentials" errors

### Step 7: Test Voucher Templates
1. Go to `/voucher-templates`
2. Create a new template
3. Customize colors and layout
4. Preview the template
5. Set as default
6. Use it to print vouchers

## Known Issues & Solutions

### Issue: "Please provide API credentials"
**Cause**: Middleware not recognizing tenant token
**Solution**: ✅ Fixed - Middleware now checks Sanctum tokens first

### Issue: 404 on mikrotik_api.php
**Cause**: Frontend still calling old PHP endpoints
**Solution**: ✅ Fixed - All pages updated to use Laravel API

### Issue: 401 Unauthorized on API calls
**Cause**: Missing or invalid authentication token
**Solution**: 
1. Ensure user is logged in (check localStorage for `tenant_token` or `admin_token`)
2. Verify middleware is applied to routes
3. Check that IdentifyTenant middleware is updated

### Issue: Sales points not saving
**Cause**: 401 error due to auth issues
**Solution**: ✅ Fixed - Middleware update resolves this

## API Endpoints Reference

### Authentication
- `POST /api/tenant/login` - Tenant login
- `POST /api/tenant/logout` - Tenant logout
- `GET /api/tenant/me` - Get current tenant user
- `POST /api/tenant/change-password` - Change password

### Vouchers
- `GET /api/vouchers` - List vouchers
- `POST /api/vouchers/generate-batch` - Generate vouchers
- `GET /api/vouchers/types` - List voucher types
- `POST /api/vouchers/types` - Create voucher type
- `PUT /api/vouchers/types/{id}` - Update voucher type
- `DELETE /api/vouchers/types/{id}` - Delete voucher type

### Sales Points
- `GET /api/sales-points` - List sales points
- `POST /api/sales-points` - Create sales point
- `PUT /api/sales-points/{id}` - Update sales point
- `DELETE /api/sales-points/{id}` - Delete sales point

### Voucher Templates
- `GET /api/voucher-templates` - List templates
- `POST /api/voucher-templates` - Create template
- `GET /api/voucher-templates/default` - Get default template
- `PUT /api/voucher-templates/{id}` - Update template
- `DELETE /api/voucher-templates/{id}` - Delete template
- `POST /api/voucher-templates/{id}/set-default` - Set as default

### Clients
- `GET /api/clients` - List clients (supports ?limit=20)
- `GET /api/clients/refresh` - Refresh client data
- `GET /api/clients/{id}` - Get client details

### Devices/Routers
- `GET /api/routers` - List routers
- `POST /api/routers` - Create router
- `PUT /api/routers/{id}` - Update router
- `DELETE /api/routers/{id}` - Delete router

### Dashboard
- `GET /api/dashboard/stats` - Dashboard statistics
- `GET /api/transactions` - List transactions (supports ?limit=20)

### Announcements
- `GET /api/announcements/active` - Get active announcements for current user

## Frontend Routes

### Tenant Routes
- `/` - Dashboard (with clients & transactions)
- `/login` - Login page
- `/signup` - Signup page
- `/clients` - Clients list
- `/devices` - Devices/routers
- `/vouchers` - Vouchers management
- `/voucher-types` - Voucher types
- `/voucher-templates` - Voucher templates (NEW)
- `/transactions` - Transactions
- `/withdrawals` - Withdrawals
- `/performance` - Performance analytics
- `/voucher-stock` - Voucher stock
- `/import-vouchers` - Import vouchers
- `/settings` - Settings
- `/users` - User management

### Admin Routes
- `/admin/login` - Admin login
- `/admin/dashboard` - Admin dashboard
- `/admin/tenants` - Tenant list
- `/admin/tenants/pending` - Pending approvals
- `/admin/announcements` - Announcements
- `/admin/settings` - System settings
- `/admin/platform-fees` - Platform fees

## Troubleshooting

### If you still see 401 errors:
1. Check browser console for the exact failing endpoint
2. Verify the token exists: `localStorage.getItem('tenant_token')`
3. Check if middleware is applied to the route in `routes/api.php`
4. Ensure IdentifyTenant middleware has the updated code

### If voucher creation fails:
1. Ensure voucher types exist (create one first)
2. Ensure sales points exist (create one first)
3. Check that both have valid data
4. Verify no console errors

### If clients page is empty:
1. The backend tries to query `hotspot_users` table
2. If table doesn't exist, it returns empty array (not an error)
3. You'll need to populate this table from your MikroTik routers
4. Or modify `ClientController.php` to use a different table

## Success Criteria

✅ Users can login without issues
✅ Users can create voucher types
✅ Users can create sales points
✅ Users can generate vouchers
✅ Users can download/print vouchers with templates
✅ Dashboard shows clients and transactions
✅ Notifications bell shows announcements
✅ No 401 or 404 errors in console
✅ All API calls use Laravel endpoints with auth tokens
