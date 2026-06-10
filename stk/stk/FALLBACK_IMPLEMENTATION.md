# Automatic Payment Fallback System

## Overview

This system automatically retries failed YO Payments transactions with the IOTEC API as a backup payment service. When a payment fails on the primary YO API (especially "Data Invalid" errors), the system seamlessly switches to IOTEC without requiring user intervention.

## How It Works

### Flow Diagram

```
User initiates payment
    ↓
YO API processes payment
    ↓
    ├─→ SUCCESS → Assign voucher → Done
    ├─→ PENDING → Keep polling
    └─→ FAILED/DECLINED/DATA INVALID
            ↓
        Automatic Fallback Triggered
            ↓
        IOTEC API processes payment
            ↓
            ├─→ SUCCESS → Assign voucher → Done
            ├─→ PENDING → Keep polling
            └─→ FAILED → Notify user of failure
```

### Key Features

1. **Automatic Detection**: System detects YO API failures including "Data Invalid" errors
2. **Seamless Retry**: Automatically initiates IOTEC payment without user action
3. **User Notification**: Frontend displays "Trying backup payment service..." message
4. **Transparent Tracking**: Both databases track the fallback relationship
5. **No Double Charging**: Original failed transaction is marked, new IOTEC transaction created

## Files Modified/Created

### New Files

1. **`payment_fallback_helper.php`**
   - Contains `retryPaymentWithIOTEC()` function
   - Contains `checkIOTECFallbackStatus()` function
   - Handles all fallback logic

2. **`run_migration.php`**
   - Database migration script
   - Adds `fallback_ref` column to transactions table

3. **`add_fallback_column.sql`**
   - SQL migration file
   - Can be run manually if preferred

### Modified Files

1. **`check_status.php`**
   - Integrated fallback detection
   - Returns status code 2 for "retrying with backup"
   - Checks IOTEC status when fallback exists

2. **`login.html`**
   - Added CSS for backup status display (orange/amber styling)
   - Updated polling logic to handle status code 2
   - Displays user-friendly "Trying backup service" message

## Installation Steps

### 1. Run Database Migration

```bash
php /var/www/html/BiteTechsystems/yo/run_migration.php
```

Or manually run the SQL:

```sql
ALTER TABLE transactions 
ADD COLUMN IF NOT EXISTS fallback_ref VARCHAR(255) DEFAULT NULL AFTER status_message;

CREATE INDEX IF NOT EXISTS idx_fallback_ref ON transactions(fallback_ref);
```

### 2. Update IOTEC Database (Optional but Recommended)

Run on your IOTEC database:

```sql
ALTER TABLE transactions 
ADD COLUMN IF NOT EXISTS fallback_from VARCHAR(255) DEFAULT NULL AFTER status_message;

CREATE INDEX IF NOT EXISTS idx_fallback_from ON transactions(fallback_from);
```

### 3. Verify File Permissions

Ensure the new files are readable by your web server:

```bash
chmod 644 /var/www/html/BiteTechsystems/yo/payment_fallback_helper.php
chmod 644 /var/www/html/BiteTechsystems/yo/run_migration.php
```

## Status Codes

The system uses the following transaction status codes:

- **`1`**: Success - Payment completed, voucher assigned
- **`0`**: Pending - Payment in progress, waiting for confirmation
- **`-1`**: Failed - Payment failed (both services exhausted)
- **`2`**: Retrying - Primary service failed, trying backup service

## Frontend User Experience

### Normal Flow
1. User clicks "Buy Voucher"
2. Sees: "📱 Check your phone to enter PIN..."
3. Sees: "⏳ Waiting for payment confirmation..."
4. Sees: "✅ Payment confirmed"
5. Auto-login with voucher

### Fallback Flow
1. User clicks "Buy Voucher"
2. Sees: "📱 Check your phone to enter PIN..."
3. Sees: "⏳ Waiting for payment confirmation..."
4. **Sees: "🔄 Primary service failed. Trying backup payment service..."** (Orange/Amber)
5. Sees: "⏳ Waiting for payment confirmation..." (for IOTEC)
6. Sees: "✅ Payment confirmed"
7. Auto-login with voucher

## Database Schema Changes

### YO Payments Database (`payment_mikrotik`)

```sql
transactions table:
- fallback_ref VARCHAR(255) -- Stores IOTEC external_ref when fallback is triggered
```

### IOTEC Database

```sql
transactions table:
- fallback_from VARCHAR(255) -- Stores original YO external_ref (optional tracking)
```

## Logging

All fallback operations are logged with the following tags:

- `[INFO]` - Fallback initiation
- `[SUCCESS]` - Fallback payment succeeded
- `[ERROR]` - Fallback failed
- `[WARNING]` - YO API failure detected

Example log entries:

```
[2026-03-29 03:00:00] [INFO] YO! API reports FAILED/DECLINED - Attempting IOTEC fallback
[2026-03-29 03:00:01] [INFO] Initiating automatic fallback to IOTEC API
[2026-03-29 03:00:02] [INFO] IOTEC fallback transaction created in database
[2026-03-29 03:00:05] [SUCCESS] IOTEC fallback payment initiated successfully
```

## Testing

### Test Scenario 1: YO API Fails, IOTEC Succeeds

1. Initiate a payment that will fail on YO API
2. System should automatically retry with IOTEC
3. User sees orange "Trying backup service" message
4. Payment completes via IOTEC
5. User receives voucher

### Test Scenario 2: Both Services Fail

1. Initiate a payment that will fail on both services
2. System tries YO API first
3. System tries IOTEC as fallback
4. User sees error message only after both fail

### Test Scenario 3: YO API Succeeds

1. Normal payment flow
2. No fallback triggered
3. User never sees backup service message

## Troubleshooting

### Issue: Fallback not triggering

**Check:**
- `payment_fallback_helper.php` is included in `check_status.php`
- Database has `fallback_ref` column
- IOTEC API credentials are configured in `IOTEC/config.php`

### Issue: Database error during fallback

**Check:**
- Migration was run successfully
- Both databases are accessible
- User has INSERT/UPDATE permissions

### Issue: Frontend not showing backup message

**Check:**
- Browser cache (hard refresh with Ctrl+Shift+R)
- Console for JavaScript errors
- Status code 2 is being returned from backend

## Performance Considerations

- **Polling Frequency**: 5 seconds (unchanged)
- **Max Polls**: 40 attempts = ~3 minutes total
- **Fallback Delay**: Immediate upon YO API failure detection
- **Additional API Calls**: +1 IOTEC initiate call, +N IOTEC status checks

## Security

- All API credentials remain in respective config files
- No sensitive data exposed to frontend
- Database transactions maintain referential integrity
- Fallback tracking prevents duplicate retries

## Future Enhancements

Potential improvements:

1. **Configurable Fallback Rules**: Choose which errors trigger fallback
2. **Multiple Fallback Services**: Chain more than 2 payment providers
3. **Smart Routing**: Route to best service based on success rates
4. **Analytics Dashboard**: Track fallback frequency and success rates
5. **Notification System**: Alert admins when fallback rate is high

## Support

For issues or questions:
- Check logs in `/var/www/html/BiteTechsystems/yo/logs/`
- Check IOTEC logs in `/var/www/html/BiteTechsystems/yo/IOTEC/logs/`
- Contact: 0786979317

---

**Implementation Date**: March 29, 2026  
**Version**: 1.0  
**Status**: Production Ready
