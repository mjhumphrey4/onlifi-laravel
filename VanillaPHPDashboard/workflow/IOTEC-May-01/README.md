# IOTEC Mobile Money Integration

This folder contains the IOTEC Pay API integration for mobile money payments.

## Setup Instructions

### 1. Configure API Credentials

Edit `config.php` and replace the placeholder values with your actual IOTEC credentials:

```php
define('IOTEC_CLIENT_ID', 'your-actual-client-id');
define('IOTEC_CLIENT_SECRET', 'your-actual-client-secret');
define('IOTEC_WALLET_ID', 'your-actual-wallet-id');
```

**Where to get your credentials:**
- Your `client_id` and `client_secret` are sent to your email when you sign up for IOTEC Pay
- Your `wallet_id` can be found in the IOTEC Pay portal (https://pay.iotec.io)
- If you can't find the email, check spam/promotions or contact support@iotec.io

### 2. Configure Callback URL

1. Log in to the IOTEC Pay portal: https://pay.iotec.io
2. Navigate to your wallet's details page
3. Under the Settings tab, locate the "Callback URLs" card
4. Click "Add" and configure:
   - **Callback Category**: Collection
   - **Callback URL**: `https://your-domain.com/yo/IOTEC/callback.php`
   - **Security Headers**: (Optional) Add any authentication headers if needed

### 3. File Permissions

Ensure the IOTEC folder has write permissions for token caching:

```bash
chmod 755 /var/www/html/yo/IOTEC
chmod 666 /var/www/html/yo/IOTEC/token_cache.json (will be created automatically)
```

## How It Works

### Payment Flow

1. **Initiate Payment** (`initiate.php`)
   - Receives payment request from frontend
   - Authenticates with IOTEC using OAuth2
   - Creates collection request via IOTEC API
   - Stores transaction in database
   - Returns transaction reference to frontend

2. **Check Status** (`check_status.php`)
   - Frontend polls this endpoint to check payment status
   - First checks local database for status
   - If not found/pending, queries IOTEC API
   - Updates database and assigns voucher on success
   - **Sends SMS ONLY for successful transactions with voucher codes**
   - Returns status to frontend

3. **Callback Handler** (`callback.php`)
   - IOTEC sends automatic notifications when transaction status changes
   - Receives real-time webhooks from ioTec Pay API
   - Updates transaction status in database
   - Calculates telecom fee (4%) and platform fee (UGX 2,000 for first daily transaction per site)
   - Assigns voucher to successful transactions
   - **Special handling for 10,000/= 7-day package**: Assigns TWO vouchers
   - **Sends SMS notification ONLY for successful transactions with voucher codes**
   
   **ioTec Callback Payload Fields:**
   - `id`: ioTec transaction ID
   - `externalId`: Our external reference (IOTEC_timestamp_uniqid)
   - `status`: Transaction status (SentToVendor, Success, Failed, Declined, Cancelled)
   - `statusCode`: Status code (pending, success, not-enough-funds, etc.)
   - `statusMessage`: Human-readable status message
   - `amount`: Transaction amount
   - `payer`: Phone number (256XXXXXXXXX)
   - `payerName`: Customer name from mobile money
   - `vendor`: Mobile money provider (Mtn, Airtel)
   - `vendorTransactionId`: Provider's transaction reference

### SMS Integration

- **Location:** `sms_helper.php` (in IOTEC folder)
- **Trigger:** Only successful transactions with assigned vouchers
- **Provider:** CommsSDK (PahappaLimited)
- **Sender ID:** STK WIFI
- **No parent directory dependencies** - fully self-contained

### Authentication

- Uses OAuth2 client credentials flow
- Access tokens are cached in `token_cache.json`
- Tokens auto-refresh when expired (300 seconds before expiry)

## API Endpoints

### Initiate Payment
```
POST /yo/IOTEC/initiate.php
Content-Type: application/json

{
  "amount": 1000,
  "msisdn": "256771234567",
  "origin_site": "STK WIFI",
  "client_mac": "AA:BB:CC:DD:EE:FF",
  "voucher_type": "24hours",
  "origin_url": "http://example.com"
}
```

### Check Status
```
GET /yo/IOTEC/check_status.php?ref=IOTEC_1234567890_abc123
```

## Testing

IOTEC provides test phone numbers for testing:
- Check the ONLIFI/ioTec.html documentation for test numbers
- Use sandbox credentials for testing before going live

## Differences from Yo Payments

### Simpler Integration
- **Authentication**: OAuth2 (simpler than Yo's custom auth)
- **No Complex SDK**: Direct REST API calls
- **Automatic Callbacks**: Built-in webhook support
- **Cleaner API**: Modern REST endpoints

### Same Features
- Mobile money collections
- Transaction status checking
- IPN/Callback notifications
- SMS notifications
- Voucher assignment

## Troubleshooting

### Check Logs
```bash
# Callback logs
tail -f /var/www/html/BiteTechsystems/yo/IOTEC/logs/iotec_callback_*.txt

# General IOTEC logs
tail -f /var/www/html/BiteTechsystems/yo/IOTEC/logs/iotec_*.txt
```

### Background Polling (Fallback)
The `poll_pending_transactions.php` script runs as a cron job to check pending transactions:
```bash
# Run every minute via cron
* * * * * /usr/bin/php /var/www/html/BiteTechsystems/yo/IOTEC/poll_pending_transactions.php >> /var/log/iotec_polling.log 2>&1
```

### Common Issues

1. **Authentication Failed**
   - Verify client_id and client_secret in config.php
   - Check if credentials are from the correct environment (sandbox vs production)

2. **Callback Not Received**
   - Verify callback URL is configured in IOTEC portal
   - Check that callback.php is accessible from the internet
   - Review callback logs

3. **Token Cache Issues**
   - Delete token_cache.json to force token refresh
   - Check file permissions

## Support

For IOTEC API issues, contact: support@iotec.io
