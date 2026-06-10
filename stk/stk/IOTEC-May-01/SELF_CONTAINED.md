# IOTEC Folder - Fully Self-Contained

This IOTEC folder is now **100% self-contained** with no external dependencies outside this directory.

## ✅ What's Included

### Configuration
- **config.php** - All database and IOTEC API settings merged into one file
  - Database: `payment_mikrotik` on `localhost`
  - IOTEC credentials: client_id, client_secret, wallet_id
  - Site URL configuration

### Core Files
- **initiate.php** - Payment initiation endpoint
- **check_status.php** - Transaction status polling
- **callback.php** - IOTEC webhook/IPN handler
- **auth_helper.php** - OAuth2 authentication with token caching
- **logger.php** - Logging system
- **voucher_helper.php** - Voucher assignment logic
- **sms_helper.php** - SMS notification system

### Supporting Files
- **test_auth.php** - Test IOTEC authentication
- **view_logs.php** - View log files in browser
- **login_example.html** - Working payment example page

### Documentation
- **README.md** - Complete setup guide
- **INTEGRATION_GUIDE.md** - 2-step integration instructions
- **SMS_INTEGRATION.md** - SMS documentation
- **SELF_CONTAINED.md** - This file

### Directories
- **logs/** - All IOTEC logs stored here
- **vendor/** - Composer dependencies (CommsSDK for SMS)

## 🔒 No External Dependencies

All parent directory references have been removed:

❌ **OLD (removed):**
```php
require_once '../config.php';
require_once '../voucher_helper.php';
require_once '../vendor/autoload.php';
$logFile = __DIR__ . '/../logs/';
```

✅ **NEW (self-contained):**
```php
require_once 'config.php';
require_once 'voucher_helper.php';
require_once 'vendor/autoload.php';
$logFile = __DIR__ . '/logs/';
```

## 📁 Complete Folder Structure

```
IOTEC/
├── config.php                  # Merged DB + IOTEC config
├── auth_helper.php             # OAuth2 authentication
├── logger.php                  # Logging system
├── voucher_helper.php          # Voucher assignment
├── sms_helper.php              # SMS notifications
├── initiate.php                # Payment initiation
├── check_status.php            # Status polling
├── callback.php                # Webhook handler
├── test_auth.php               # Auth testing
├── view_logs.php               # Log viewer
├── login_example.html          # Test page
├── README.md                   # Setup guide
├── INTEGRATION_GUIDE.md        # Integration steps
├── SMS_INTEGRATION.md          # SMS docs
├── SELF_CONTAINED.md           # This file
├── logs/                       # Log directory
│   ├── iotec_YYYY-MM-DD.txt
│   └── iotec_callback_YYYY-MM-DD.txt
└── vendor/                     # Composer dependencies
    ├── autoload.php
    ├── composer/
    ├── guzzlehttp/
    ├── pahappa-limited/
    ├── psr/
    ├── ralouphie/
    └── symfony/
```

## 🚀 Deployment

You can now **copy this entire IOTEC folder** to any server and it will work independently:

```bash
# Copy to another server
scp -r /var/www/html/yo/IOTEC user@server:/var/www/html/

# Or zip and transfer
cd /var/www/html/yo
tar -czf iotec-complete.tar.gz IOTEC/
```

## ⚙️ Configuration Required

Only edit **one file** to configure:

**File:** `config.php`

```php
// Database settings
define('DB_HOST', 'localhost');
define('DB_NAME', 'payment_mikrotik');
define('DB_USER', 'yo');
define('DB_PASS', 'password');

// Site URL
define('SITE_URL', 'https://your-domain.com/yo/');

// IOTEC API credentials
define('IOTEC_CLIENT_ID', 'your-client-id');
define('IOTEC_CLIENT_SECRET', 'your-client-secret');
define('IOTEC_WALLET_ID', 'your-wallet-id');
```

## 📊 Database Tables Used

- **transactions** - Payment records
- **vouchers** - Voucher codes

These tables should exist in your `payment_mikrotik` database.

## 🔐 Permissions

Ensure proper permissions:

```bash
chmod 755 /var/www/html/yo/IOTEC
chmod 755 /var/www/html/yo/IOTEC/logs
chmod 644 /var/www/html/yo/IOTEC/*.php
chmod 644 /var/www/html/yo/IOTEC/*.html
```

## ✅ Verification

Test that everything is self-contained:

1. **Test authentication:**
   ```
   https://your-domain.com/yo/IOTEC/test_auth.php
   ```

2. **Test payment:**
   ```
   https://your-domain.com/yo/IOTEC/login_example.html
   ```

3. **View logs:**
   ```
   https://your-domain.com/yo/IOTEC/view_logs.php
   ```

## 📝 Summary

- ✅ All configuration in one place (`config.php`)
- ✅ All dependencies included (`vendor/`)
- ✅ All logs stored locally (`logs/`)
- ✅ All helper functions included
- ✅ No parent directory references
- ✅ Fully portable and deployable
- ✅ Complete documentation included

**The IOTEC folder is now a standalone, self-contained payment system!**
