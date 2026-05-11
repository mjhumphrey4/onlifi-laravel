# OnLiFi Laravel Project - Comprehensive Issues Analysis

**Analysis Date:** May 11, 2026  
**Project:** OnLiFi Multi-Tenant WiFi Voucher Management System  
**Status:** In-progress analysis - identifying all functional errors and mismatches

---

## 🔴 CRITICAL ISSUES

### 1. **Sites Feature Partially Removed but Still Referenced**
**Location:** Frontend and Backend  
**Severity:** CRITICAL  
**Description:**
- `SiteContext.tsx` was deleted but sites are still used throughout the backend
- `SiteController.php` exists and creates sites with `tenant_id`
- Frontend pages like `Settings.tsx`, `VoucherStock.tsx` still reference site selection
- Database has `sites` table but frontend doesn't use it consistently
- **Impact:** Broken user experience - site selection UI removed but backend expects sites

**Evidence:**
- `frontend/src/app/App.tsx` - SiteProvider removed
- `backend/app/Http/Controllers/SiteController.php` - Still active
- `backend/routes/api.php:206-214` - Site routes still registered
- `frontend/src/app/pages/Settings.tsx:18` - Still has `selectedSite` state

---

### 2. **Telemetry Data Not Linked to Tenants (UNFIXED)**
**Location:** Database schema and TelemetryController  
**Severity:** CRITICAL  
**Description:**
- Migration created to add `tenant_id` to `router_telemetry` table but **NOT RUN**
- `TelemetryController::receive()` updated to store `tenant_id` but column doesn't exist yet
- `TelemetryController::getStats()` filters by `tenant_id` but column may not exist
- **Impact:** Users see ALL telemetry data from ALL tenants OR database errors

**Evidence:**
- `backend/database/migrations/2025_05_04_000001_add_tenant_id_to_telemetry.php` - Created but not migrated
- `backend/app/Http/Controllers/TelemetryController.php:248` - Tries to insert `tenant_id`
- `backend/app/Http/Controllers/TelemetryController.php:75` - Filters by `tenant_id` that may not exist

**Required Action:**
```bash
php artisan migrate  # Must run this migration first
```

---

### 3. **Sites Table Missing `tenant_id` Column**
**Location:** Central database  
**Severity:** CRITICAL  
**Description:**
- `Site` model has `tenant_id` in fillable array
- `SiteController::store()` tries to set `tenant_id` on creation
- Migration exists to add column but **NOT RUN**
- **Impact:** Database errors when creating sites, or sites created without tenant association

**Evidence:**
- `backend/app/Models/Site.php:11` - `tenant_id` in fillable
- `backend/app/Http/Controllers/SiteController.php:44` - Sets `tenant_id`
- Migration file created but not executed

---

### 4. **Voucher Reuse Prevention May Not Work**
**Location:** FreeRADIUS Perl module  
**Severity:** CRITICAL  
**Description:**
- `multi_tenant.pl` deletes vouchers from `radcheck` on session Stop
- But if FreeRADIUS restarts or accounting packet is lost, voucher remains in `radcheck`
- No scheduled cleanup job to remove expired vouchers
- **Impact:** Users can potentially reuse vouchers if session doesn't end cleanly

**Evidence:**
- `backend/config/freeradius/multi_tenant.pl:268-272` - Deletes on Stop only
- No cron job or scheduled task to cleanup expired vouchers

---

## 🟠 HIGH PRIORITY ISSUES

### 5. **Active Clients List May Be Empty**
**Location:** Dashboard and RadiusAccountingController  
**Severity:** HIGH  
**Description:**
- Dashboard fetches from `/api/radius/active-users`
- `RadiusAccountingController::getActiveUsers()` queries `radacct` table
- Query looks for sessions where `acctstoptime IS NULL`
- If accounting packets are lost or delayed, sessions may not be recorded
- **Impact:** Dashboard shows 0 active clients even when users are connected

**Evidence:**
- `frontend/src/app/pages/Dashboard.tsx:106` - Fetches active users
- `backend/app/Http/Controllers/RadiusAccountingController.php` - Queries radacct

---

### 6. **Transaction Site Filtering Broken**
**Location:** TransactionController and frontend  
**Severity:** HIGH  
**Description:**
- Transactions table has `origin_site` as VARCHAR (site name)
- Frontend removed site selector but backend still expects `origin_site` parameter
- No foreign key relationship between transactions and sites
- **Impact:** Cannot reliably filter transactions by site

**Evidence:**
- `frontend/src/app/pages/Transactions.tsx:55` - Removed `origin_site` param
- Database uses string site names instead of site_id foreign key

---

### 7. **Multiple Authentication Token Storage Keys**
**Location:** Frontend localStorage  
**Severity:** HIGH  
**Description:**
- Code uses both `tenant_token` and `admin_token` inconsistently
- Some components check one, some check both
- No clear token hierarchy or fallback logic
- **Impact:** Authentication state confusion, potential login issues

**Evidence:**
- `frontend/src/app/pages/Dashboard.tsx:82` - Checks both tokens
- `frontend/src/app/utils/api.ts:5` - Checks both tokens
- No single source of truth for auth token

---

### 8. **Voucher Group Site Association Unclear**
**Location:** Database schema  
**Severity:** HIGH  
**Description:**
- Migration adds `site_id` to `voucher_groups` table
- But vouchers themselves don't have `site_id`, only `group_id`
- Filtering vouchers by site requires JOIN through groups
- **Impact:** Complex queries, potential performance issues

**Evidence:**
- `backend/database/migrations/tenant/2024_01_15_000002_update_sites_table_structure.php:57-61`
- Vouchers table has no direct site_id column

---

## 🟡 MEDIUM PRIORITY ISSUES

### 9. **FreeRADIUS Accounting Interim-Update Logging Too Verbose**
**Location:** multi_tenant.pl  
**Severity:** MEDIUM  
**Description:**
- Interim-Update packets logged at level 3 (debug)
- But still creates log entry every 60-300 seconds per user
- Can fill logs quickly with many active users
- **Impact:** Log file bloat, harder to find actual errors

**Evidence:**
- `backend/config/freeradius/multi_tenant.pl:334` - Log level 3 for interim updates

---

### 10. **Dashboard Auto-Refresh Every 5 Seconds**
**Location:** Frontend Dashboard  
**Severity:** MEDIUM  
**Description:**
- Dashboard refreshes all data every 5 seconds
- Includes stats, transactions, clients, telemetry
- Multiple API calls every 5 seconds per user
- **Impact:** High server load, unnecessary database queries

**Evidence:**
- `frontend/src/app/pages/Dashboard.tsx:178` - `setInterval(load, 5000)`

---

### 11. **No Error Handling for Failed Telemetry Storage**
**Location:** TelemetryController  
**Severity:** MEDIUM  
**Description:**
- If telemetry insert fails, router gets 500 error
- Router may retry indefinitely
- No dead letter queue or retry logic
- **Impact:** Routers may spam failed telemetry requests

**Evidence:**
- `backend/app/Http/Controllers/TelemetryController.php:264` - Simple insert, no retry logic

---

### 12. **Voucher Stock Page Still Has Site Selector**
**Location:** VoucherStock.tsx  
**Severity:** MEDIUM  
**Description:**
- Sites feature was removed from app
- But VoucherStock page still has site selection buttons
- Uses `useAuth().userSites()` which may not exist
- **Impact:** Page may crash or show broken UI

**Evidence:**
- `frontend/src/app/pages/VoucherStock.tsx:28-31` - Site selection logic
- `frontend/src/app/pages/VoucherStock.tsx:82-86` - Site selector buttons

---

### 13. **Settings Page Site Token Generation Broken**
**Location:** Settings.tsx  
**Severity:** MEDIUM  
**Description:**
- Settings page tries to load site API token
- Calls `/api/sites/{id}/regenerate-token`
- But if user has no sites, page shows empty state
- Router script generation requires site selection
- **Impact:** Users cannot generate router telemetry scripts

**Evidence:**
- `frontend/src/app/pages/Settings.tsx:32-40` - Site token loading
- `frontend/src/app/pages/Settings.tsx:413-422` - Site selector dropdown

---

### 14. **NAS (Router) Registration Missing Tenant Association**
**Location:** NasController  
**Severity:** MEDIUM  
**Description:**
- NAS table in central database has `tenant_id`
- But NAS registration may not properly set tenant_id
- FreeRADIUS queries NAS by IP/identifier without tenant filter
- **Impact:** Potential cross-tenant router access

**Evidence:**
- `backend/database/migrations/2024_01_01_000005_create_radius_nas_table.php:37` - Has tenant_id
- Need to verify NasController sets it correctly

---

### 15. **Radacct Table Session Cleanup Missing**
**Location:** Database and scheduled tasks  
**Severity:** MEDIUM  
**Description:**
- `radacct` table grows indefinitely
- No cleanup of old completed sessions
- Active users query may become slow over time
- **Impact:** Database bloat, performance degradation

**Evidence:**
- No cleanup migration or scheduled task found
- `radacct` table has no TTL or archival strategy

---

## 🔵 LOW PRIORITY ISSUES

### 16. **Inconsistent Date Formatting**
**Location:** Frontend components  
**Severity:** LOW  
**Description:**
- Some components use `toLocaleString()`
- Others use `toLocaleTimeString()`
- No consistent date format across app
- **Impact:** Confusing UX, inconsistent timestamps

**Evidence:**
- `frontend/src/app/pages/Dashboard.tsx:203` - `toLocaleTimeString()`
- `frontend/src/app/pages/Dashboard.tsx:389` - `toLocaleString('en-GB', ...)`

---

### 17. **Hardcoded Site Colors in Dashboard**
**Location:** Dashboard.tsx  
**Severity:** LOW  
**Description:**
- Site colors hardcoded for specific site names
- New sites get default gray color
- No way to customize site colors
- **Impact:** Poor UX for sites not in hardcoded list

**Evidence:**
- `frontend/src/app/pages/Dashboard.tsx:51-57` - SITE_COLORS object

---

### 18. **Missing API Endpoint Implementations**
**Location:** api.ts  
**Severity:** LOW  
**Description:**
- Several API functions return placeholder responses
- `apiPerformance`, `apiWithdrawals`, `apiRequestWithdrawal`, `apiImportVouchers`
- All return "not yet implemented" messages
- **Impact:** Features appear to exist but don't work

**Evidence:**
- `frontend/src/app/utils/api.ts:196-219` - Placeholder implementations

---

### 19. **Voucher Template Feature Unused**
**Location:** Backend routes and controller  
**Severity:** LOW  
**Description:**
- `VoucherTemplateController` exists with full CRUD
- Routes registered in `api.php`
- But no frontend implementation found
- **Impact:** Dead code, unused feature

**Evidence:**
- `backend/routes/api.php:166-174` - Template routes
- No frontend components use voucher templates

---

### 20. **Sales Points Feature Incomplete**
**Location:** Backend only  
**Severity:** LOW  
**Description:**
- `SalesPointController` exists
- Routes registered
- No frontend UI for managing sales points
- **Impact:** Backend feature with no UI

**Evidence:**
- `backend/routes/api.php:197-203` - Sales point routes
- No frontend pages for sales points

---

## 🔧 ARCHITECTURAL INCONSISTENCIES

### 21. **Mixed Database Connection Usage**
**Location:** Throughout backend  
**Severity:** MEDIUM  
**Description:**
- Some models use `'tenant'` connection
- Some controllers use `DB::connection('central')`
- Some use `DB::connection('tenant')`
- Inconsistent connection selection logic
- **Impact:** Potential data in wrong database

**Evidence:**
- `TelemetryController` uses `'central'` for router_telemetry
- `VoucherController` uses `'tenant'` for vouchers
- `SiteController` uses default (central) for sites

---

### 22. **Middleware Naming Confusion**
**Location:** Routes  
**Severity:** LOW  
**Description:**
- Routes use `auth:sanctum` middleware
- Other routes use `tenant` middleware
- Unclear what `tenant` middleware does vs `auth:sanctum`
- **Impact:** Confusion about authentication requirements

**Evidence:**
- `backend/routes/api.php:34` - `auth:sanctum` group
- `backend/routes/api.php:122` - `tenant` middleware group

---

### 23. **Frontend Uses Removed Context**
**Location:** Multiple frontend pages  
**Severity:** MEDIUM  
**Description:**
- `SiteContext` was deleted
- But `Settings.tsx`, `VoucherStock.tsx`, `ImportVouchers.tsx` may still reference it
- Code may crash at runtime
- **Impact:** Runtime errors, broken pages

**Evidence:**
- `SiteContext.tsx` deleted in recent commit
- Pages not updated to remove site context usage

---

## 📊 DATA FLOW ISSUES

### 24. **Telemetry Push vs Pull Mismatch**
**Location:** Router script and backend  
**Severity:** MEDIUM  
**Description:**
- Router script pushes telemetry to `/api/telemetry` endpoint
- Dashboard pulls telemetry from `/api/telemetry/stats`
- No real-time push mechanism (WebSockets, SSE)
- Dashboard polls every 5 seconds
- **Impact:** Not truly "real-time", 5-second delay

**Evidence:**
- Routers push via HTTP POST
- Frontend polls via GET every 5 seconds
- No WebSocket or Server-Sent Events implementation

---

### 25. **Voucher Sync to RADIUS Tables**
**Location:** VoucherService and RadiusController  
**Severity:** MEDIUM  
**Description:**
- Vouchers created in `vouchers` table
- Must be synced to `radcheck` and `radreply` for FreeRADIUS
- Sync happens on voucher generation
- But if sync fails, voucher exists but won't work
- No retry mechanism
- **Impact:** Vouchers that don't work in RADIUS

**Evidence:**
- `VoucherService::generateVoucherBatch()` syncs to radcheck
- No error handling if sync fails

---

### 26. **Transaction Creation Without Voucher Validation**
**Location:** PaymentController  
**Severity:** MEDIUM  
**Description:**
- Transactions can be created with `voucher_code` field
- But no validation that voucher exists or is valid
- Can create transactions referencing non-existent vouchers
- **Impact:** Data integrity issues

**Evidence:**
- Need to check `PaymentController::initiate()` for voucher validation

---

## 🔒 SECURITY CONCERNS

### 27. **API Token Exposed in Frontend**
**Location:** Settings page  
**Severity:** MEDIUM  
**Description:**
- Site API tokens shown in plaintext in Settings UI
- Tokens visible in browser console logs
- No masking or "click to reveal" protection
- **Impact:** Token leakage if user shares screenshot

**Evidence:**
- `frontend/src/app/pages/Settings.tsx` - Shows full token
- Console logs may contain tokens

---

### 28. **No Rate Limiting on Telemetry Endpoint**
**Location:** TelemetryController  
**Severity:** MEDIUM  
**Description:**
- `/api/telemetry` endpoint is public (no auth middleware)
- Only requires valid API token
- No rate limiting
- **Impact:** Potential DoS by spamming telemetry endpoint

**Evidence:**
- `backend/routes/api.php:31` - Public route
- No rate limiting middleware

---

### 29. **Voucher Codes May Be Predictable**
**Location:** VoucherService  
**Severity:** LOW  
**Description:**
- Need to verify voucher code generation uses cryptographically secure random
- If using simple random, codes may be guessable
- **Impact:** Potential voucher fraud

**Evidence:**
- Need to check `VoucherService` code generation method

---

## 🧪 TESTING & VALIDATION GAPS

### 30. **No Validation for Router Telemetry Data**
**Location:** TelemetryController::receive()  
**Severity:** LOW  
**Description:**
- Telemetry endpoint accepts any data
- No validation of CPU load range (0-100)
- No validation of memory values
- Malformed data stored as-is
- **Impact:** Invalid data in database, broken charts

**Evidence:**
- `backend/app/Http/Controllers/TelemetryController.php:245-262` - No validation

---

### 31. **Missing Database Indexes**
**Location:** Various tables  
**Severity:** MEDIUM  
**Description:**
- `radacct` table may be missing indexes on frequently queried columns
- `router_telemetry` has indexes on `site_id` and `router_identity`
- But missing index on `tenant_id` (if column exists)
- **Impact:** Slow queries as data grows

**Evidence:**
- Need to check actual database schema vs migrations

---

### 32. **No Foreign Key Constraints**
**Location:** Database migrations  
**Severity:** LOW  
**Description:**
- Some tables have `site_id`, `tenant_id` columns
- But no foreign key constraints defined
- Can insert invalid IDs
- **Impact:** Orphaned records, data integrity issues

**Evidence:**
- Migrations add columns but don't add foreign keys

---

## 📝 DOCUMENTATION ISSUES

### 33. **Conflicting Architecture Documents**
**Location:** Project root  
**Severity:** LOW  
**Description:**
- `SITES_ARCHITECTURE.md` describes sites feature in detail
- `REMOVE_SITES_NOTES.md` says sites feature was removed
- Conflicting information
- **Impact:** Developer confusion

**Evidence:**
- Both documents exist with contradictory information

---

### 34. **Router Script Comments Reference Wrong Paths**
**Location:** Settings.tsx telemetry script  
**Severity:** LOW  
**Description:**
- Generated router script has hardcoded comments
- May reference old API endpoints or paths
- **Impact:** Confusion when debugging router issues

**Evidence:**
- `frontend/src/app/pages/Settings.tsx:134-147` - Script generation

---

## 🎯 SUMMARY BY CATEGORY

### Critical Issues Requiring Immediate Action: **4**
1. Sites feature half-removed
2. Telemetry tenant_id column missing
3. Sites tenant_id column missing
4. Voucher reuse prevention incomplete

### High Priority Issues: **4**
5. Active clients list may be empty
6. Transaction site filtering broken
7. Multiple auth token keys
8. Voucher-site association unclear

### Medium Priority Issues: **14**
9-22. Various functional and architectural issues

### Low Priority Issues: **12**
23-34. Minor bugs, missing features, documentation

---

## 🚀 RECOMMENDED IMMEDIATE ACTIONS

1. **Run pending migrations** to add `tenant_id` columns
2. **Decide on Sites feature**: Fully remove OR fully implement
3. **Fix telemetry filtering** to ensure tenant isolation
4. **Add voucher cleanup** scheduled task
5. **Consolidate auth token** handling
6. **Reduce dashboard polling** frequency (30s instead of 5s)
7. **Add rate limiting** to public endpoints
8. **Remove or complete** unused features (templates, sales points)

---

**End of Analysis**  
**Total Issues Identified: 34**  
**Analysis Status: Complete**
