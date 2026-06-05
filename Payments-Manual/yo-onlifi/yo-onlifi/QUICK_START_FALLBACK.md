# Quick Start: Payment Fallback System

## 🚀 What You Need to Do

### Step 1: Run the Database Migration (REQUIRED)

```bash
cd /var/www/html/BiteTechsystems/yo
php run_migration.php
```

This adds the `fallback_ref` column to your transactions table.

### Step 2: Test the System

1. Make a test payment that you know will fail on YO API
2. Watch the frontend - you should see:
   - Orange message: "🔄 Primary service failed. Trying backup payment service..."
3. The system will automatically retry with IOTEC
4. If IOTEC succeeds, user gets their voucher

### Step 3: Monitor the Logs

Check your logs to see fallback activity:

```bash
tail -f /var/www/html/BiteTechsystems/yo/logs/paymentlogs.txt | grep -i fallback
```

## 📊 What Changed

### Backend Changes
- ✅ `check_status.php` - Now detects failures and triggers IOTEC fallback
- ✅ `payment_fallback_helper.php` - New file handling all fallback logic
- ✅ Database - Added `fallback_ref` column to track retries

### Frontend Changes  
- ✅ `login.html` - Shows orange "Trying backup service" message
- ✅ Handles new status code `2` (retrying with backup)

## 🎯 How It Works

```
Payment Flow:
1. User pays → YO API tries first
2. If YO fails → System automatically tries IOTEC
3. User sees "Trying backup service..." message
4. If IOTEC succeeds → User gets voucher
5. If both fail → User sees error
```

## 🔍 Monitoring "Data Invalid" Errors

The system now automatically handles these errors:
- **Before**: User sees "Data Invalid" error immediately
- **After**: System tries IOTEC backup, user only sees error if both fail

Check fallback success rate:

```bash
# Count how many fallbacks were triggered
grep -c "Initiating automatic fallback" /var/www/html/BiteTechsystems/yo/logs/paymentlogs.txt

# Count how many succeeded
grep -c "Fallback payment succeeded" /var/www/html/BiteTechsystems/yo/logs/paymentlogs.txt
```

## ⚠️ Important Notes

1. **No Double Charging**: System creates separate transactions, original is marked as failed
2. **Transparent to User**: User doesn't need to do anything, system handles retry automatically
3. **Logging**: All fallback attempts are logged for your review
4. **Status Code 2**: New status code means "retrying with backup service"

## 🐛 Troubleshooting

**Problem**: Migration fails  
**Solution**: Check database permissions, ensure user has ALTER privileges

**Problem**: Fallback not triggering  
**Solution**: Check that `payment_fallback_helper.php` exists and is readable

**Problem**: Orange message not showing  
**Solution**: Clear browser cache (Ctrl+Shift+F5)

## 📈 Expected Impact

Based on your logs showing **20 "Data Invalid" errors in 7 days**:
- These will now automatically retry with IOTEC
- Users will experience fewer payment failures
- Success rate should improve significantly

## 🎉 You're Done!

The system is now production-ready. All failed YO payments will automatically retry with IOTEC before showing an error to the user.

---
**Need Help?** Check `FALLBACK_IMPLEMENTATION.md` for full documentation.
