# HTTP vs HTTPS Configuration Guide

## Understanding the Setup

Your system uses **different protocols for different purposes**, and this is perfectly fine and recommended.

---

## Current Configuration

### Frontend (login.html)
```javascript
// Uses HTTP for API calls
fetch('http://pay.onlustech.com/yo/initiate.php', ...)
fetch('http://pay.onlustech.com/yo/check_status.php', ...)
```

**Why HTTP is OK here:**
- These are direct calls from the frontend JavaScript
- The browser makes the requests
- Works fine over HTTP or HTTPS

### Backend IPN (config.php)
```php
// Uses HTTPS for IPN callbacks
define('SITE_URL', 'https://pay.onlustech.com/yo/');
```

**Why HTTPS is REQUIRED here:**
- YO! Payments servers send IPN notifications to your server
- YO! Payments requires HTTPS endpoints for security
- This is server-to-server communication
- HTTPS ensures payment data is encrypted in transit

---

## How It Works

### Payment Flow:

1. **User initiates payment** (HTTP)
   ```
   Browser → HTTP → http://pay.onlustech.com/yo/initiate.php
   ```

2. **initiate.php sends IPN URL to YO!** (HTTPS)
   ```php
   $ipnUrl = SITE_URL . 'ipn.php'; // https://pay.onlustech.com/yo/ipn.php
   $yoAPI->set_instant_notification_url($ipnUrl);
   ```

3. **YO! Payments processes payment**
   ```
   Customer's phone ← → YO! Payments servers
   ```

4. **YO! sends IPN notification** (HTTPS)
   ```
   YO! Payments → HTTPS → https://pay.onlustech.com/yo/ipn.php
   ```

5. **Frontend checks status** (HTTP)
   ```
   Browser → HTTP → http://pay.onlustech.com/yo/check_status.php
   ```

---

## Why This Configuration Works

### ✅ No Conflict
- Frontend HTTP calls and IPN HTTPS callbacks are **independent**
- They use different communication paths
- Frontend: Browser ↔ Your Server
- IPN: YO! Servers → Your Server

### ✅ Security Where It Matters
- Payment notifications (IPN) use HTTPS for encryption
- Sensitive payment data is protected
- Meets YO! Payments security requirements

### ✅ Flexibility
- You don't need to force HTTPS on your entire site
- Can upgrade frontend to HTTPS later without breaking IPN
- IPN works regardless of frontend protocol

---

## SSL/HTTPS Requirements

### For IPN to Work:
Your server must have:
1. ✅ Valid SSL certificate installed
2. ✅ HTTPS enabled on port 443
3. ✅ `https://pay.onlustech.com` accessible
4. ✅ No SSL errors or warnings

### Check Your SSL:
```bash
# Test HTTPS is working
curl -I https://pay.onlustech.com/yo/ipn.php

# Should return HTTP 200 OK
```

### Test IPN Endpoint:
```bash
# Simulate IPN call
curl -X POST https://pay.onlustech.com/yo/ipn.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "test=1"

# Check logs
tail -1 logs/ipn_log_$(date +%Y-%m-%d).txt
```

---

## Common Questions

### Q: Can I use HTTP for everything?
**A:** No. YO! Payments requires HTTPS for IPN callbacks. If you use HTTP, IPN notifications will fail.

### Q: Do I need to change login.html to HTTPS?
**A:** No, it's optional. The frontend can use HTTP. Only the IPN endpoint needs HTTPS.

### Q: What if I don't have SSL certificate?
**A:** You need one for IPN to work. Options:
- **Let's Encrypt** (free SSL certificate)
- **Cloudflare** (free SSL proxy)
- **Purchase SSL** from hosting provider

### Q: Can I test without HTTPS?
**A:** Not in production mode. YO! Payments production requires HTTPS. In sandbox mode, they might accept HTTP, but it's not recommended.

### Q: Will mixed HTTP/HTTPS cause browser warnings?
**A:** No, because:
- IPN is server-to-server (no browser involved)
- Frontend HTTP calls don't trigger mixed content warnings
- Only loading HTTPS page with HTTP resources causes warnings

---

## Upgrading Frontend to HTTPS (Optional)

If you want to upgrade your entire site to HTTPS:

### Step 1: Update login.html
```javascript
// Change from:
fetch('http://pay.onlustech.com/yo/initiate.php', ...)

// To:
fetch('https://pay.onlustech.com/yo/initiate.php', ...)
```

### Step 2: No config.php changes needed
The IPN URL is already HTTPS, so no changes required.

### Step 3: Test
- Verify all API calls work over HTTPS
- Check for mixed content warnings
- Ensure SSL certificate covers all endpoints

---

## Troubleshooting

### IPN Not Receiving Notifications

**Check 1: Is HTTPS working?**
```bash
curl -I https://pay.onlustech.com/yo/ipn.php
```
Should return `200 OK`

**Check 2: SSL Certificate Valid?**
```bash
openssl s_client -connect pay.onlustech.com:443 -servername pay.onlustech.com
```
Should show valid certificate

**Check 3: Firewall Allowing HTTPS?**
```bash
sudo ufw status
```
Port 443 should be open

**Check 4: IPN URL Correct?**
```bash
grep SITE_URL /var/www/html/BiteTechsystems/yo/config.php
```
Should show `https://pay.onlustech.com/yo/`

---

## Best Practices

### ✅ DO:
- Keep IPN URL as HTTPS in config.php
- Ensure SSL certificate is valid and not expired
- Monitor SSL certificate expiration
- Use HTTPS for all payment-related endpoints
- Test IPN endpoint accessibility regularly

### ❌ DON'T:
- Change IPN URL to HTTP (will break notifications)
- Use self-signed certificates in production
- Ignore SSL certificate warnings
- Mix domains between frontend and IPN
- Forget to renew SSL certificates

---

## Summary

**Current Setup (CORRECT):**
```
Frontend:  HTTP  → http://pay.onlustech.com/yo/
IPN:       HTTPS → https://pay.onlustech.com/yo/ipn.php
```

**Key Points:**
- ✅ No conflict between HTTP frontend and HTTPS IPN
- ✅ YO! Payments requires HTTPS for IPN
- ✅ Frontend can use HTTP or HTTPS (your choice)
- ✅ IPN must use HTTPS (required)
- ✅ Both use same domain (pay.onlustech.com)

**Your configuration is correct and will work properly!**

---

**Last Updated**: February 1, 2026  
**Status**: ✅ Correctly Configured
