# ONLIFI-Manager

Android-only mobile app for ONLIFI. The app is now a native Android dashboard that talks directly to ONLIFI-Laravel APIs and keeps the user's ONLIFI login after the first successful sign-in. After that first ONLIFI login, app access is protected by the phone's own screen lock: PIN, pattern, password, or biometric where Android offers it.

## What it does

- Uses native ONLIFI tenant login with existing 2FA support.
- Stores the tenant bearer token after first login.
- Requires Android device credential before showing a saved ONLIFI session.
- Shows a native dashboard with site selection, active users, earnings, mobile money, vouchers, and available balance.
- Shows cached dashboard data immediately, then refreshes ONLIFI in the background.
- Uses ONLIFI's dark blue, green, and orange visual palette.
- Includes Home, Vouchers, Mobile Money, and Account tabs.
- Supports voucher creation, voucher group PDF downloads, voucher types, sales points, templates, and manual vouchers through ONLIFI APIs.
- Automatically starts a PDF download after voucher generation.
- Requests download/notification permissions where Android requires them.
- Uses HTTPS-only networking.
- Disables backup of app storage so saved sessions are not copied to cloud/device transfer.
- Prevents screenshots while the app is open.
- Supports Android 6.0+ with target SDK 35.

## Project

- App name: `ONLIFI-Manager`
- Package/application ID: `net.onlifi.manager`
- Platform: Android only
- Main activity: `app/src/main/java/net/onlifi/manager/MainActivity.java`

## Laravel notes

Withdrawal submission and exact available-balance settlement need Laravel endpoints that are not currently present in the main ONLIFI codebase. See:

```text
ONLIFI_LARAVEL_MOBILE_MONEY_REQUIREMENTS.md
```

## Build

Open this folder in Android Studio:

```text
ONLIFI-Manager
```

Or build from a terminal that has Java available:

```bash
./gradlew :app:assembleDebug
```

To point the app at another ONLIFI API base URL:

```bash
./gradlew :app:assembleDebug -PonlifiApiBaseUrl=https://api.onlifi.net/api
```

## Play Store release

Create a keystore once:

```bash
keytool -genkeypair -v -keystore onlifi-manager-release.jks -alias onlifi-manager -keyalg RSA -keysize 2048 -validity 10000
```

Add signing values to `~/.gradle/gradle.properties` or pass them as Gradle properties:

```properties
ONLIFI_RELEASE_STORE_FILE=C:/path/to/onlifi-manager-release.jks
ONLIFI_RELEASE_STORE_PASSWORD=replace-with-store-password
ONLIFI_RELEASE_KEY_ALIAS=onlifi-manager
ONLIFI_RELEASE_KEY_PASSWORD=replace-with-key-password
```

Build the Play Store app bundle:

```bash
./gradlew :app:bundleRelease
```

The bundle will be created at:

```text
app/build/outputs/bundle/release/app-release.aab
```

Before publishing, replace the generated launcher mark with the final ONLIFI brand artwork if you have official app icon assets.
