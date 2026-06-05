# SMS Integration Explanation

## How SMS Works in IOTEC

The IOTEC integration has its **own SMS helper file** located directly in the IOTEC folder for better organization and independence.

## SMS Files Location

The SMS functionality is located in the **IOTEC folder**:

- **`/var/www/html/yo/IOTEC/sms_helper.php`** - Contains the `sendVoucherSMS()` function
- This is a self-contained copy specifically for IOTEC
- No parent directory dependencies

## Where SMS is Triggered

**IMPORTANT: SMS is ONLY sent for SUCCESSFUL transactions with assigned voucher codes.**

SMS notifications are sent in **3 places**, all with strict success conditions:

### 1. In `check_status.php` (Lines 96-105)
When the frontend polls for status and finds a successful payment:
```php
if ($transaction && $transaction['status'] === 'success') {
    if ($voucherResult['success']) {
        require_once 'sms_helper.php';
        logIotec("Sending SMS to " . $transaction['msisdn'], 'SMS');
        $smsResult = sendVoucherSMS($transaction['msisdn'], $voucherResult['voucherCode'], '');
        logIotec("SMS send result", 'SMS', ['success' => $smsResult['success'], 'message' => $smsResult['message']]);
    }
}
```

### 2. In `check_status.php` (Lines 180-189)
When IOTEC API reports success during polling:
```php
if ($apiStatus === 'success') {
    if ($voucherResult['success']) {
        require_once 'sms_helper.php';
        $smsResult = sendVoucherSMS($transaction['msisdn'], $voucherResult['voucherCode'], '');
    }
}
```

### 3. In `callback.php` (Lines 80-90)
When IOTEC sends a webhook notification with success status:
```php
if ($status === 'success') {
    if ($voucherResult['success']) {
        $smsResult = sendVoucherSMS($transaction['msisdn'], $voucherResult['voucherCode'], '');
        
        if ($smsResult['success']) {
            logIotecCallback("SMS sent successfully", 'SMS_SUCCESS');
        } else {
            logIotecCallback("SMS sending failed", 'SMS_ERROR');
        }
    }
}
```

**Failed transactions = NO SMS**
**Pending transactions = NO SMS**
**Only successful transactions with voucher codes = SMS SENT**

## How It Works

1. **Payment succeeds** (either via polling or callback)
2. **Voucher is assigned** using `assignVoucherToTransaction()` from `voucher_helper.php`
3. **SMS is sent** using `sendVoucherSMS()` from `sms_helper.php`
4. **SMS delivery is logged** in the IOTEC logs

## SMS Logging

All SMS attempts are now logged in:
```
/var/www/html/yo/logs/iotec_YYYY-MM-DD.txt
```

Look for log entries with type `[SMS]`, `[SMS_SUCCESS]`, or `[SMS_ERROR]`.

## Verify SMS Configuration

Check the SMS configuration in:
```
/var/www/html/yo/IOTEC/sms_helper.php
```

The IOTEC integration uses CommsSDK with the following credentials:
- **Username:** humphreympairwe
- **Sender ID:** STK WIFI
- **API Key:** Configured in sms_helper.php (line 32)

## No Duplication

The system is designed to send SMS **only once** per transaction:
- If callback arrives first → SMS sent via callback
- If polling finds success first → SMS sent via polling
- The voucher assignment function prevents duplicate vouchers
- Therefore, no duplicate SMS messages

## Testing SMS

To verify SMS is working:

1. **Check the logs:**
   ```bash
   tail -f /var/www/html/yo/logs/iotec_*.txt | grep SMS
   ```

2. **Look for these log entries:**
   - `[SMS] Sending SMS to 256...`
   - `[SMS_SUCCESS] SMS sent successfully`
   - `[SMS_ERROR] SMS sending failed` (if there's an issue)

3. **Verify your phone receives the voucher code**

## Summary

- ✅ SMS files are in `/var/www/html/yo/` (shared with Yo Payments)
- ✅ SMS is sent automatically when payment succeeds
- ✅ SMS sending is logged in IOTEC logs
- ✅ No duplicate SMS messages
- ✅ Uses your existing SMS provider configuration
