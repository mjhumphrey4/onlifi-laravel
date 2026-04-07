# Removing Sites Feature - Implementation Notes

## What Was Done

### Phase 1: Frontend Cleanup ✅
- Removed `SiteContext.tsx` and `SiteProvider`
- Removed site selector from Transactions page
- Updated `App.tsx` to remove SiteProvider wrapper

### Phase 2: Remaining Frontend Work 🔄

**Files that still reference sites/site selection:**

1. **VoucherStock.tsx**
   - Has site selector buttons
   - Filters stock by selected site
   - Remove site selector, show all voucher stock

2. **Settings.tsx**
   - Has site dropdown for router script generation
   - Shows site API token
   - Simplify to show single API token for user's account
   - Remove site selection from router script

3. **ImportVouchers.tsx**
   - May have site references (needs verification)

4. **Dashboard.tsx**
   - Currently doesn't use sites
   - Keep as is

### Phase 3: Backend Simplification

**No changes needed** - backend already works without site filtering:
- Telemetry API returns all user's routers
- Vouchers API returns all user's vouchers
- Transactions API returns all user's transactions
- Active users API returns all active sessions

The backend was already designed to work with or without site_id parameter.

## Simplified User Experience

**Before (with Sites):**
- User creates multiple sites
- Each site has separate routers, vouchers, transactions
- User switches between sites to see different data
- Complex context switching

**After (without Sites):**
- User has one account
- User manages all their routers directly
- User sees all vouchers, transactions, clients in one view
- Simple, straightforward interface

## Router Script Changes

**Before:**
```routeros
:local apiToken "SITE_SPECIFIC_TOKEN"
:local siteSlug "my-site-slug"
```

**After:**
```routeros
:local apiToken "USER_ACCOUNT_TOKEN"
# No site slug needed
```

## API Token Management

**Before:**
- Each site had its own API token
- Tokens were site-specific
- Router script included site slug

**After:**
- User account has one API token (or generate per router)
- Token is user-specific
- Router script is simpler

## Database Impact

**No migration needed:**
- `sites` table still exists but unused
- `site_id` columns in tables are nullable
- Data without site_id works fine
- Can clean up later if needed

## Testing Checklist

- [ ] Build frontend without errors
- [ ] Dashboard shows all routers
- [ ] Vouchers page shows all vouchers (no site filter)
- [ ] Transactions page shows all transactions (no site filter)
- [ ] Settings page shows router script without site selection
- [ ] Active clients list shows all connected users
- [ ] Telemetry displays all router stats

## Deployment Steps

```bash
cd /var/www/onlifi

# Pull latest changes
git pull origin main

# Rebuild frontend
cd frontend
npm run build

# Deploy to public
cd ..
cp -r frontend/dist/* public/

# No backend changes needed
# No database migrations needed
```

## Future Cleanup (Optional)

If we want to fully remove sites from database:

1. Remove `site_id` columns from tables (optional, can leave as nullable)
2. Drop `sites` table (optional, can leave unused)
3. Remove site-related routes from `api.php`
4. Remove `SiteController.php`

**Recommendation:** Leave database as-is for now. The nullable `site_id` columns don't hurt anything.
