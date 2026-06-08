# ONLIFI Installer

Standalone Android-only app for installers who add deployed devices into ONLIFI.

This project is intentionally separate from `ONLIFI-Manager`. It does not open the ONLIFI dashboard and it does not depend on the manager app. Installers log in to this app, capture device install details, and the app uploads the records to ONLIFI when internet is available.

## What It Does

- Installer login against the ONLIFI installer API.
- Add a new installed device with:
  - Device name or router label.
  - Unique IPv4 address.
  - Latitude and longitude from GPS or manual entry.
  - Front photo.
  - Back photo.
  - Install notes.
- Saves records locally first using SQLite.
- Keeps pending uploads on the phone when offline.
- Automatically retries uploads when the app opens/resumes and when the installer taps Sync.
- Deletes successfully uploaded local records and photos from the phone.
- Sends uploaded devices to the ONLIFI router list API.
- Leaves Uptime Kuma tracking as an ONLIFI Admin-side integration step.

## Project

- App name: `ONLIFI Installer`
- Folder: `ONLIFI-Installer`
- Package/application ID: `net.onlifi.installer`
- Platform: Android only
- Minimum Android: 6.0, API 23
- Target SDK: 35
- Main activity: `app/src/main/java/net/onlifi/installer/MainActivity.java`

## Build

Open this folder in Android Studio:

```text
ONLIFI-Installer
```

Or build from a terminal with Java available:

```bash
./gradlew :app:assembleDebug
```

Windows PowerShell:

```powershell
.\gradlew.bat :app:assembleDebug
```

To point the app at another ONLIFI installer API:

```bash
./gradlew :app:assembleDebug -PonlifiInstallerApiUrl=https://onlifi.net/api/installer
```

## Expected API

The app expects:

- `POST /api/installer/login`
- `POST /api/installer/devices`

See `ONLIFI_SIDE_REQUIREMENTS.md` for the Laravel-side requirements. No ONLIFI Laravel files were changed by this app scaffold.

## Release Signing

Create a keystore once:

```bash
keytool -genkeypair -v -keystore onlifi-installer-release.jks -alias onlifi-installer -keyalg RSA -keysize 2048 -validity 10000
```

Add signing values to `~/.gradle/gradle.properties` or pass them as Gradle properties:

```properties
ONLIFI_INSTALLER_RELEASE_STORE_FILE=C:/path/to/onlifi-installer-release.jks
ONLIFI_INSTALLER_RELEASE_STORE_PASSWORD=replace-with-store-password
ONLIFI_INSTALLER_RELEASE_KEY_ALIAS=onlifi-installer
ONLIFI_INSTALLER_RELEASE_KEY_PASSWORD=replace-with-key-password
```

Build the Play Store bundle:

```bash
./gradlew :app:bundleRelease
```

The bundle will be created at:

```text
app/build/outputs/bundle/release/app-release.aab
```
