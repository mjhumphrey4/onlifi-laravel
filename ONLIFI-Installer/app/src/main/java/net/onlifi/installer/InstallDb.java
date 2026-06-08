package net.onlifi.installer;

import android.content.ContentValues;
import android.content.Context;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;

import java.io.File;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;

final class InstallDb extends SQLiteOpenHelper {
    private static final String DB_NAME = "onlifi_installer.db";
    private static final int DB_VERSION = 1;

    InstallDb(Context context) {
        super(context, DB_NAME, null, DB_VERSION);
    }

    @Override
    public void onCreate(SQLiteDatabase db) {
        db.execSQL("CREATE TABLE installations (" +
                "id INTEGER PRIMARY KEY AUTOINCREMENT," +
                "local_id TEXT NOT NULL UNIQUE," +
                "installer_id TEXT NOT NULL," +
                "device_name TEXT NOT NULL," +
                "ip_address TEXT NOT NULL UNIQUE," +
                "latitude REAL NOT NULL," +
                "longitude REAL NOT NULL," +
                "front_photo_path TEXT NOT NULL," +
                "back_photo_path TEXT NOT NULL," +
                "notes TEXT," +
                "created_at INTEGER NOT NULL," +
                "last_error TEXT" +
                ")");
    }

    @Override
    public void onUpgrade(SQLiteDatabase db, int oldVersion, int newVersion) {
        db.execSQL("DROP TABLE IF EXISTS installations");
        onCreate(db);
    }

    long add(Installation installation) {
        ContentValues values = new ContentValues();
        values.put("local_id", installation.localId == null ? UUID.randomUUID().toString() : installation.localId);
        values.put("installer_id", installation.installerId);
        values.put("device_name", installation.deviceName);
        values.put("ip_address", installation.ipAddress);
        values.put("latitude", installation.latitude);
        values.put("longitude", installation.longitude);
        values.put("front_photo_path", installation.frontPhotoPath);
        values.put("back_photo_path", installation.backPhotoPath);
        values.put("notes", installation.notes);
        values.put("created_at", installation.createdAt == 0L ? System.currentTimeMillis() : installation.createdAt);
        values.put("last_error", installation.lastError);
        return getWritableDatabase().insertOrThrow("installations", null, values);
    }

    boolean ipExists(String ipAddress) {
        try (Cursor cursor = getReadableDatabase().rawQuery(
                "SELECT id FROM installations WHERE ip_address = ? LIMIT 1",
                new String[]{ipAddress})) {
            return cursor.moveToFirst();
        }
    }

    int pendingCount() {
        try (Cursor cursor = getReadableDatabase().rawQuery(
                "SELECT COUNT(*) FROM installations",
                new String[]{})) {
            return cursor.moveToFirst() ? cursor.getInt(0) : 0;
        }
    }

    List<Installation> pending() {
        List<Installation> installations = new ArrayList<>();
        try (Cursor cursor = getReadableDatabase().rawQuery(
                "SELECT id, local_id, installer_id, device_name, ip_address, latitude, longitude, " +
                        "front_photo_path, back_photo_path, notes, created_at, last_error " +
                        "FROM installations ORDER BY created_at ASC",
                new String[]{})) {
            while (cursor.moveToNext()) {
                installations.add(fromCursor(cursor));
            }
        }
        return installations;
    }

    void saveError(long id, String error) {
        ContentValues values = new ContentValues();
        values.put("last_error", error);
        getWritableDatabase().update("installations", values, "id = ?", new String[]{String.valueOf(id)});
    }

    void deleteSynced(Installation installation) {
        getWritableDatabase().delete("installations", "id = ?", new String[]{String.valueOf(installation.id)});
        deleteFile(installation.frontPhotoPath);
        deleteFile(installation.backPhotoPath);
    }

    private Installation fromCursor(Cursor cursor) {
        Installation installation = new Installation();
        installation.id = cursor.getLong(0);
        installation.localId = cursor.getString(1);
        installation.installerId = cursor.getString(2);
        installation.deviceName = cursor.getString(3);
        installation.ipAddress = cursor.getString(4);
        installation.latitude = cursor.getDouble(5);
        installation.longitude = cursor.getDouble(6);
        installation.frontPhotoPath = cursor.getString(7);
        installation.backPhotoPath = cursor.getString(8);
        installation.notes = cursor.getString(9);
        installation.createdAt = cursor.getLong(10);
        installation.lastError = cursor.getString(11);
        return installation;
    }

    private void deleteFile(String path) {
        if (path == null || path.isEmpty()) {
            return;
        }
        File file = new File(path);
        if (file.exists()) {
            file.delete();
        }
    }
}
