package net.onlifi.installer;

import android.content.Context;
import android.content.SharedPreferences;

final class InstallerStore {
    private static final String PREFS = "onlifi_installer";
    private static final String TOKEN = "token";
    private static final String INSTALLER_ID = "installer_id";
    private static final String INSTALLER_NAME = "installer_name";
    private static final String LAST_SYNC_AT = "last_sync_at";

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

    long getLastSyncAt() {
        return preferences.getLong(LAST_SYNC_AT, 0L);
    }

    void saveLogin(String token, String installerId, String installerName) {
        preferences.edit()
                .putString(TOKEN, token == null ? "" : token)
                .putString(INSTALLER_ID, installerId == null ? "" : installerId)
                .putString(INSTALLER_NAME, installerName == null || installerName.isEmpty() ? "Installer" : installerName)
                .apply();
    }

    void saveLastSyncAt(long timeMillis) {
        preferences.edit().putLong(LAST_SYNC_AT, timeMillis).apply();
    }

    void clearSession() {
        preferences.edit()
                .remove(TOKEN)
                .remove(INSTALLER_ID)
                .remove(INSTALLER_NAME)
                .remove(LAST_SYNC_AT)
                .apply();
    }
}
