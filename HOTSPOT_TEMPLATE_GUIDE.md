# Hotspot Template Editing Guide

This guide explains where to manually edit OnLiFi captive portal templates and MikroTik hotspot support files.

## Main Login Pages

OnLiFi generates these files from the application, so do not edit the copied MikroTik support versions for branding:

- `login.html`
- `alogin.html`

The generated `login.html` comes from the first available file in this order:

1. `backend/resources/hotspot/login-manual.html`
2. `backend/resources/hotspot/login.html`
3. `OLD-Flow/login.html`
4. `EgoSMS Flow/login.html`

Recommended manual override:

```text
backend/resources/hotspot/login-manual.html
```

Create that file when you want a custom production login design. The app will use it before the bundled legacy templates.

Useful placeholders in the login template:

```text
{{SITE_NAME}}
{{SITE_SLUG}}
{{DESTINATION_DIRECTORY}}
{{SITE_NAME_FIELD}}
{{PAYMENT_INITIATE_URL}}
{{MANUAL_PAYMENT_URL}}
{{PAYMENT_CHECK_STATUS_URL}}
{{VOUCHER_LOOKUP_URL}}
{{API_BASE_URL}}
{{ROUTER_TOKEN}}
```

Keep MikroTik variables such as `$(link-login-only)`, `$(chap-id)`, `$(chap-challenge)`, `$(link-orig)`, and `$(error)` intact when the page uses them.

## Other Hotspot Directory Files

The app copies the extra MikroTik hotspot support files from:

```text
Voucher Templates/hotspot-dir/hotspot/
```

These files are safe to edit there:

```text
status.html
logout.html
error.html
errors.txt
radvert.html
redirect.html
rlogin.html
md5.js
css/style.css
img/*
xml/*
favicon.ico
api.json
```

Important:

- `Voucher Templates/hotspot-dir/hotspot/login.html` is ignored by OnLiFi.
- `Voucher Templates/hotspot-dir/hotspot/alogin.html` is ignored by OnLiFi.
- Edit `backend/resources/hotspot/login-manual.html` for the main branded login design instead.

## Making Support Files Template-Aware

Support files are currently copied as static files. If you want a support file to receive site-specific values, add placeholders manually and then update `backend/app/Services/CaptivePortalService.php` to render that file instead of returning it as static content.

Suggested placeholder names:

```text
{{SITE_NAME}}
{{API_BASE_URL}}
{{ROUTER_TOKEN}}
{{PAYMENT_CHECK_STATUS_URL}}
```

After editing templates, deploy and rebuild caches:

```bash
cd /var/www/onlifi/backend
php artisan optimize:clear
php artisan config:cache
sudo systemctl restart php8.3-fpm
```

Then download the captive portal ZIP again from the dashboard and upload the full `hotspot/` directory to MikroTik.
