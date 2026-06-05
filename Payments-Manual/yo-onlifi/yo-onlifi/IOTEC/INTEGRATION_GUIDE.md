# How to Integrate IOTEC into Your Existing login.html

This guide shows you exactly how to modify your existing `login.html` to use the IOTEC payment system instead of Yo Payments.

## Quick Integration (2 Simple Changes)

### Change 1: Update the Initiate Payment URL

In your `login.html`, find this line (around line 1293):

```javascript
fetch('http://pay.onlustech.com/yo/initiate.php', {
```

**Replace with:**

```javascript
fetch('http://pay.onlustech.com/yo/IOTEC/initiate.php', {
```

### Change 2: Update the Check Status URL

In your `login.html`, find this line (around line 1371):

```javascript
fetch(`http://pay.onlustech.com/yo/check_status.php?ref=${encodeURIComponent(ref)}&t=${Date.now()}`)
```

**Replace with:**

```javascript
fetch(`http://pay.onlustech.com/yo/IOTEC/check_status.php?ref=${encodeURIComponent(ref)}&t=${Date.now()}`)
```

## That's It!

Those are the **only two changes** needed in your login.html file. The IOTEC integration:

- Uses the same payment flow as Yo Payments
- Accepts the same parameters
- Returns the same response format
- Works with your existing voucher system
- Sends SMS notifications the same way

## Before Going Live

1. **Configure your IOTEC credentials** in `/var/www/html/yo/IOTEC/config.php`:
   ```php
   define('IOTEC_CLIENT_ID', 'your-actual-client-id');
   define('IOTEC_CLIENT_SECRET', 'your-actual-client-secret');
   define('IOTEC_WALLET_ID', 'your-actual-wallet-id');
   ```

2. **Set up the callback URL** in the IOTEC Pay portal:
   - Log in to https://pay.iotec.io
   - Go to your wallet settings
   - Add callback URL: `https://your-domain.com/yo/IOTEC/callback.php`

3. **Test the integration** using the example page:
   - Open: `http://your-domain.com/yo/IOTEC/login_example.html`
   - Try a test payment
   - Verify you receive the voucher code

## Testing vs Production

For testing, you can run both systems side-by-side:

- **Yo Payments**: Keep using `yo/initiate.php` and `yo/check_status.php`
- **IOTEC**: Test with `yo/IOTEC/initiate.php` and `yo/IOTEC/check_status.php`

Once you're satisfied with IOTEC, simply update the two URLs in login.html to switch over.

## Advantages of IOTEC

1. **Simpler Authentication**: OAuth2 instead of custom Yo API
2. **Automatic Callbacks**: No need to poll constantly
3. **Better Documentation**: Modern REST API
4. **Easier Debugging**: Clear error messages and logs
5. **Same Features**: All your existing functionality works

## Need Help?

- Check the logs: `/var/www/html/yo/logs/iotec_callback_*.txt`
- Review the README: `/var/www/html/yo/IOTEC/README.md`
- Test with the example: `/var/www/html/yo/IOTEC/login_example.html`
- Contact IOTEC support: support@iotec.io
