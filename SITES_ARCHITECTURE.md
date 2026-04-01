# Sites Architecture - OnLiFi Multi-Tenant System

## What are Sites?

**Sites are independent accounts owned by the same user.** Each site represents a separate business location or network deployment with its own:

- **Routers**: Each site has its own MikroTik routers
- **Database**: Each site uses the same tenant database but data is filtered by `site_id`
- **Vouchers**: Site-specific voucher groups and inventory
- **Transactions**: Site-specific payment transactions
- **Clients**: Site-specific connected users
- **Telemetry**: Site-specific router statistics

---

## Key Characteristics

### 1. Auto-Approved Creation
- **No admin approval required** - registered users can create sites instantly
- Sites are created via `/api/sites` POST endpoint
- Each site gets a unique `slug` and `api_token`

### 2. Independent Operation
- Each site operates independently with its own routers
- Vouchers, transactions, and clients are isolated per site
- Telemetry data is tracked per site

### 3. Dashboard Context Switching
**When a user selects a site, the ENTIRE dashboard switches context:**
- All vouchers shown are for that site only
- All transactions are for that site only
- All clients (active users) are for that site only
- All routers and telemetry are for that site only

---

## Current Implementation Status

### ✅ What's Working

1. **Frontend Site Context** (`SiteContext.tsx`)
   - Site selection state management
   - Auto-selects first site on load
   - Provides `useSite()` hook for components

2. **Site Management** (`SiteController.php`)
   - Create sites via `/api/sites` POST
   - List user's sites via `/api/sites` GET
   - Each site has unique `api_token` for router authentication

3. **Partial Site Filtering**
   - Some pages filter by selected site (VoucherStock, Transactions)
   - Settings page shows site-specific router script

### ⚠️ What Needs Fixing

1. **Inconsistent Site Filtering**
   - Dashboard doesn't filter by selected site
   - Vouchers page may not filter by site
   - Clients list doesn't filter by site
   - Telemetry shows all sites instead of selected site

2. **Database Schema**
   - `sites` table exists in **central** database
   - `sites` table **does NOT have `tenant_id` column**
   - Need to add `tenant_id` to sites table for proper user-site relationship

3. **Router-Site Relationship**
   - Routers should be linked to sites via `site_id`
   - Migration exists to add `site_id` to `mikrotik_routers` table
   - Need to verify this is enforced in all router operations

---

## Required Database Schema

### Central Database (`onlifi_central`)

```sql
-- Sites table (stores all sites for all tenants)
CREATE TABLE sites (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,  -- ⚠️ MISSING - needs to be added
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    api_token VARCHAR(255) UNIQUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- Router telemetry (central storage)
CREATE TABLE router_telemetry (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    site_id BIGINT UNSIGNED,  -- ✅ Already exists
    router_identity VARCHAR(255),
    cpu_load DECIMAL(5,2),
    active_users INT,
    -- ... other fields
);
```

### Tenant Database (`tenant_X_onlifi`)

```sql
-- MikroTik routers (per tenant)
CREATE TABLE mikrotik_routers (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    site_id BIGINT UNSIGNED,  -- ✅ Should exist (check migration)
    name VARCHAR(255),
    ip_address VARCHAR(45),
    -- ... other fields
);

-- Voucher groups (per tenant)
CREATE TABLE voucher_groups (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    site_id BIGINT UNSIGNED,  -- ✅ Should exist (check migration)
    name VARCHAR(255),
    -- ... other fields
);

-- Vouchers (per tenant)
CREATE TABLE vouchers (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    group_id BIGINT UNSIGNED,  -- Links to voucher_group which has site_id
    voucher_code VARCHAR(255),
    -- ... other fields
);

-- Transactions (per tenant)
CREATE TABLE transactions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    origin_site VARCHAR(255),  -- ⚠️ String-based, should be site_id
    -- ... other fields
);
```

---

## How Site Filtering Should Work

### Frontend Flow

```typescript
// 1. User selects a site from dropdown
const { selectedSite, setSelectedSite } = useSite();

// 2. All API calls include site_id filter
const response = await fetch(`/api/vouchers?site_id=${selectedSite.id}`);

// 3. Dashboard components react to site changes
useEffect(() => {
  loadData(selectedSite.id);
}, [selectedSite]);
```

### Backend Flow

```php
// 1. Get selected site from request
$siteId = $request->query('site_id');

// 2. Verify user owns this site
$site = Site::where('id', $siteId)
    ->where('tenant_id', $user->tenant_id)
    ->firstOrFail();

// 3. Filter all queries by site_id
$vouchers = Voucher::whereHas('group', function($q) use ($siteId) {
    $q->where('site_id', $siteId);
})->get();

$routers = MikrotikRouter::where('site_id', $siteId)->get();

$telemetry = RouterTelemetry::where('site_id', $siteId)->latest()->get();
```

---

## Implementation Checklist

### Database Migrations

- [ ] Add `tenant_id` column to `sites` table in central database
- [ ] Verify `site_id` exists in `mikrotik_routers` table
- [ ] Verify `site_id` exists in `voucher_groups` table
- [ ] Consider adding `site_id` to `transactions` table (currently uses `origin_site` string)

### Backend Controllers

- [ ] **SiteController**: Add `tenant_id` when creating sites
- [ ] **VoucherController**: Filter vouchers by site_id via group relationship
- [ ] **TransactionController**: Filter transactions by site
- [ ] **RadiusAccountingController**: Filter active users by site's routers
- [ ] **TelemetryController**: Filter telemetry by site_id
- [ ] **MikrotikController**: Filter routers by site_id

### Frontend Components

- [ ] **Dashboard**: Add site selector and filter all data by selected site
- [ ] **Vouchers**: Filter voucher groups and vouchers by selected site
- [ ] **Clients**: Filter active clients by selected site's routers
- [ ] **Transactions**: Already has site filtering - verify it works
- [ ] **Settings**: Already site-aware - verify router script generation

### API Endpoints

All authenticated endpoints should accept optional `site_id` parameter:

```
GET /api/vouchers?site_id=1
GET /api/transactions?site_id=1
GET /api/radius/active-users?site_id=1
GET /api/telemetry/stats?site_id=1
GET /api/routers?site_id=1
```

---

## Site Creation Flow

### Current Flow

1. User logs in to their tenant account
2. User navigates to Settings or Sites page
3. User clicks "Create Site"
4. Site is created **immediately** (no admin approval)
5. Site gets unique `api_token` for router authentication
6. User can download router script with site-specific token

### What Should Happen

```php
// SiteController@store
public function store(Request $request)
{
    $user = $request->user();
    
    $site = Site::create([
        'tenant_id' => $user->tenant_id,  // ⚠️ Currently missing
        'name' => $request->name,
        'slug' => Str::slug($request->name),
        'description' => $request->description,
        'is_active' => true,
        'api_token' => Str::random(60),
    ]);
    
    return response()->json([
        'message' => 'Site created successfully',
        'site' => $site,
    ], 201);
}
```

---

## Router-Site Relationship

### Router Registration

When a router sends telemetry:

1. Router includes `api_token` in request
2. Backend looks up site by `api_token`
3. Telemetry is stored with `site_id`
4. Router is associated with that site

### Router Script

Each site gets a unique router script:

```routeros
# Site-specific configuration
:local apiToken "SITE_UNIQUE_TOKEN"
:local siteSlug "my-site-slug"

# Telemetry endpoint includes site identification
/tool fetch url="https://api.onlifi.com/api/telemetry" \
    http-method=post \
    http-header-field="Authorization: Bearer $apiToken" \
    http-data="{\"site_slug\":\"$siteSlug\", ...}"
```

---

## Multi-Site User Experience

### Example: User with 3 Sites

**User:** John's WiFi Business
**Sites:**
1. Downtown Cafe (2 routers, 50 active vouchers)
2. Airport Lounge (5 routers, 200 active vouchers)
3. Shopping Mall (3 routers, 100 active vouchers)

**Dashboard Behavior:**

When user selects "Downtown Cafe":
- Shows 2 routers only
- Shows 50 vouchers only
- Shows clients connected to Downtown Cafe routers only
- Shows transactions from Downtown Cafe only

When user switches to "Airport Lounge":
- **Entire dashboard refreshes**
- Shows 5 different routers
- Shows 200 different vouchers
- Shows different clients
- Shows different transactions

---

## Security Considerations

1. **Site Ownership Verification**
   - Always verify `site.tenant_id == user.tenant_id`
   - Users can only access their own sites
   - API tokens are site-specific

2. **Data Isolation**
   - Vouchers are isolated per site via `group.site_id`
   - Routers are isolated per site via `router.site_id`
   - Telemetry is isolated per site via `telemetry.site_id`

3. **Cross-Site Prevention**
   - User cannot access Site A's data when Site B is selected
   - Backend must validate site_id belongs to authenticated user

---

## Summary

**Sites = Independent Business Locations**

- Each user can have multiple sites
- Each site has its own routers, vouchers, clients, transactions
- Sites are auto-created (no admin approval)
- Dashboard switches context when site is selected
- All data is filtered by selected site_id

**Current Status:**
- ✅ Site creation works
- ✅ Site selection UI exists
- ⚠️ Site filtering is inconsistent across pages
- ❌ `tenant_id` missing from sites table
- ❌ Dashboard doesn't respect selected site

**Next Steps:**
1. Add `tenant_id` to sites table
2. Implement consistent site filtering across all controllers
3. Update Dashboard to filter by selected site
4. Test multi-site user experience
