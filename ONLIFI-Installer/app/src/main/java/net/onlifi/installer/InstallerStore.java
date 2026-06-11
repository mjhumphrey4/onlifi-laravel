package net.onlifi.installer;

import android.content.Context;
import android.content.SharedPreferences;

final class InstallerStore {
    private static final String PREFS = "onlifi_installer";
    private static final String TOKEN = "token";
    private static final String INSTALLER_ID = "installer_id";
    private static final String INSTALLER_NAME = "installer_name";
    private static final String SITE_ID = "site_id";
    private static final String SITE_NAME = "site_name";
    private static final String IP_RANGE = "ip_range";
    private static final String LAST_SYNC_AT = "last_sync_at";
    private static final String LAST_SYNC_MESSAGE = "last_sync_message";
    private static final String TOTAL_UPLOADED = "total_uploaded";

    private final SharedPreferences preferences;

    InstallerStore(Context context) {
        preferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    boolean isLoggedIn() {
        return getToken().length() > 0;
    }

    String getToken() {
        return preferences.getString(TOKEN, "");
    }

    String getInstallerId() {
        return preferences.getString(INSTALLER_ID, "");
    }

    String getInstallerName() {
        return preferences.getString(INSTALLER_NAME, "Installer");
    }

    String getSiteName() {
        return preferences.getString(SITE_NAME, "Assigned site");
    }

    String getIpRange() {
        return preferences.getString(IP_RANGE, "");
    }

    long getLastSyncAt() {
        return preferences.getLong(LAST_SYNC_AT, 0L);
    }

    String getLastSyncMessage() {
        return preferences.getString(LAST_SYNC_MESSAGE, "Ready");
    }

    int getTotalUploaded() {
        return preferences.getInt(TOTAL_UPLOADED, 0);
    }

    void saveLogin(String token, String installerId, String installerName, String siteId, String siteName, String ipRange) {
        preferences.edit()
                .putString(TOKEN, token == null ? "" : token)
                .putString(INSTALLER_ID, installerId == null ? "" : installerId)
                .putString(INSTALLER_NAME, installerName == null || installerName.isEmpty() ? "Installer" : installerName)
                .putString(SITE_ID, siteId == null ? "" : siteId)
                .putString(SITE_NAME, siteName == null || siteName.isEmpty() ? "Assigned site" : siteName)
                .putString(IP_RANGE, ipRange == null ? "" : ipRange)
                .apply();
    }

    void saveSyncResult(long timeMillis, String message, int uploaded) {
        preferences.edit()
                .putLong(LAST_SYNC_AT, timeMillis)
                .putString(LAST_SYNC_MESSAGE, message == null ? "Ready" : message)
                .putInt(TOTAL_UPLOADED, preferences.getInt(TOTAL_UPLOADED, 0) + Math.max(uploaded, 0))
                .apply();
    }

    void saveSyncMessage(String message) {
        preferences.edit()
                .putString(LAST_SYNC_MESSAGE, message == null ? "Ready" : message)
                .apply();
    }

    void clearSession() {
        preferences.edit()
                .remove(TOKEN)
                .remove(INSTALLER_ID)
                .remove(INSTALLER_NAME)
                .remove(SITE_ID)
                .remove(SITE_NAME)
                .remove(IP_RANGE)
                .remove(LAST_SYNC_AT)
                .remove(LAST_SYNC_MESSAGE)
                .remove(TOTAL_UPLOADED)
                .apply();
    }
}
