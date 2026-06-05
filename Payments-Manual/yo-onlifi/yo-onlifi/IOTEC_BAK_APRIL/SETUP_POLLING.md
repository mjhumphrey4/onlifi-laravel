# IOTEC Background Polling Setup

## Problem Solved

IOTEC's callback webhook system is unreliable. Even when payments succeed, callbacks often don't reach your server, leaving transactions stuck in "pending" status. This polling system replicates the old Yo! Payments IPN behavior by actively checking transaction statuses in the background.

## How It Works

The `poll_pending_transactions.php` script runs every minute via cron and:

1. Finds all pending IOTEC transactions (created in last 24 hours, at least 30 seconds old)
2. Queries IOTEC API for current status of each transaction
3. Updates database when status changes to success/failed
4. Assigns vouchers and sends SMS for successful transactions
5. Works even if user closes browser/leaves the page

## Installation Steps

### Step 1: Fix File Permissions

Run as root or with sudo:

```bash
# Make logs directory writable by web server
sudo chown -R www-data:www-data /var/www/html/BiteTechsystems/yo/IOTEC/logs/

# Make script executable
sudo chmod +x /var/www/html/BiteTechsystems/yo/IOTEC/poll_pending_transactions.php

# Ensure token cache is writable
sudo chown www-data:www-data /var/www/html/BiteTechsystems/yo/IOTEC/token_cache.json
sudo chmod 644 /var/www/html/BiteTechsystems/yo/IOTEC/token_cache.json
```

### Step 2: Test the Script Manually

Run as www-data user to test:

```bash
sudo -u www-data /usr/bin/php /var/www/html/BiteTechsystems/yo/IOTEC/poll_pending_transactions.php
```

You should see output showing how many transactions were checked. Check the logs:

```bash
tail -f /var/www/html/BiteTechsystems/yo/IOTEC/logs/iotec_2026-*.txt
```

### Step 3: Set Up Cron Job

Add to www-data's crontab (or root if www-data can't have cron):

```bash
# Edit crontab
sudo crontab -e -u www-data

# Add this line (runs every minute):
* * * * * /usr/bin/php /var/www/html/BiteTechsystems/yo/IOTEC/poll_pending_transactions.php >> /var/www/html/BiteTechsystems/yo/IOTEC/logs/polling.log 2>&1
```

**Alternative:** If www-data can't have cron, use root but run as www-data:

```bash
sudo crontab -e

# Add this line:
* * * * * sudo -u www-data /usr/bin/php /var/www/html/BiteTechsystems/yo/IOTEC/poll_pending_transactions.php >> /var/www/html/BiteTechsystems/yo/IOTEC/logs/polling.log 2>&1
```

### Step 4: Verify Cron is Running

Wait 2-3 minutes, then check:

```bash
# Check if cron job is running
tail -20 /var/www/html/BiteTechsystems/yo/IOTEC/logs/polling.log

# Check for recent log entries
ls -lah /var/www/html/BiteTechsystems/yo/IOTEC/logs/iotec_*.txt
```

You should see new log entries every minute.

## Monitoring

### Check Polling Logs

```bash
# See recent polling activity
tail -50 /var/www/html/BiteTechsystems/yo/IOTEC/logs/polling.log

# See detailed transaction processing
tail -100 /var/www/html/BiteTechsystems/yo/IOTEC/logs/iotec_$(date +%Y-%m-%d).txt | grep POLLING
```

### Check for Stuck Transactions

```bash
# Find pending transactions older than 5 minutes
mysql -u yo -ppassword payment_mikrotik -e "
SELECT external_ref, msisdn, amount, created_at, TIMESTAMPDIFF(MINUTE, created_at, NOW()) as age_minutes
FROM transactions 
WHERE status = 'pending' 
AND transaction_ref IS NOT NULL
AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY created_at DESC;
"
```

## How This Solves Your Problem

### Before (Broken Callback System)
1. User initiates payment
2. User leaves page to enter PIN
3. Payment succeeds on IOTEC/MTN
4. **IOTEC callback never arrives** ❌
5. Transaction stuck as "pending" forever
6. No voucher assigned, no SMS sent

### After (Background Polling)
1. User initiates payment
2. User leaves page to enter PIN
3. Payment succeeds on IOTEC/MTN
4. **Polling script checks status every minute** ✅
5. Script detects success, updates database
6. Voucher assigned, SMS sent automatically
7. Works exactly like old Yo! IPN system

## Performance

- **Lock file** prevents multiple instances running simultaneously
- **100ms delay** between API requests to avoid overwhelming IOTEC
- **Limit 50 transactions** per run to keep execution time reasonable
- **30 second minimum age** prevents checking transactions too early
- **24 hour maximum age** avoids checking very old transactions

## Troubleshooting

### Cron Not Running

```bash
# Check if cron service is running
sudo systemctl status cron

# Check cron logs
sudo tail -f /var/log/syslog | grep CRON
```

### Permission Errors

```bash
# Fix all permissions
sudo chown -R www-data:www-data /var/www/html/BiteTechsystems/yo/IOTEC/
sudo chmod 755 /var/www/html/BiteTechsystems/yo/IOTEC/
sudo chmod 755 /var/www/html/BiteTechsystems/yo/IOTEC/logs/
sudo chmod +x /var/www/html/BiteTechsystems/yo/IOTEC/poll_pending_transactions.php
```

### Script Not Processing Transactions

Check if transactions meet the criteria:
- Status must be 'pending'
- Must have a transaction_ref (IOTEC transaction ID)
- Created within last 24 hours
- Created at least 30 seconds ago

### Lock File Issues

If script won't run due to stale lock:

```bash
# Remove lock file
rm /var/www/html/BiteTechsystems/yo/IOTEC/poll_pending.lock
```

## Comparison with Old Yo! Payments

| Feature | Old Yo! IPN | IOTEC Callback | IOTEC Polling |
|---------|-------------|----------------|---------------|
| Reliability | ✅ High | ❌ Low | ✅ High |
| Real-time | ✅ Yes | ✅ Yes (when works) | ⚠️ 1-min delay |
| Works when user leaves | ✅ Yes | ❌ No | ✅ Yes |
| SMS delivery | ✅ Automatic | ❌ Missed | ✅ Automatic |
| Voucher assignment | ✅ Automatic | ❌ Missed | ✅ Automatic |

## Next Steps

1. Run the installation steps above
2. Test with a real transaction
3. Monitor logs for 24 hours to ensure it's working
4. Consider keeping IOTEC callback enabled as backup (belt and suspenders approach)

## Support

If you encounter issues:
1. Check the logs first
2. Verify cron is running
3. Test script manually as www-data user
4. Check database for pending transactions
