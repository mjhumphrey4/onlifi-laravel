package net.onlifi.installer;

import org.json.JSONObject;

import java.io.BufferedInputStream;
import java.io.BufferedOutputStream;
import java.io.BufferedReader;
import java.io.DataOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

final class SyncClient {
    private final String baseUrl;

    SyncClient(String baseUrl) {
        this.baseUrl = trimTrailingSlash(baseUrl);
    }

    LoginResult login(String email, String password) throws Exception {
        URL url = new URL(baseUrl + "/login");
        HttpURLConnection connection = (HttpURLConnection) url.openConnection();
        connection.setConnectTimeout(15000);
        connection.setReadTimeout(20000);
        connection.setRequestMethod("POST");
        connection.setDoOutput(true);
        connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
        connection.setRequestProperty("Accept", "application/json");

        JSONObject body = new JSONObject();
        body.put("email", email);
        body.put("password", password);
        writeBody(connection, body.toString());

        int status = connection.getResponseCode();
        String response = readResponse(connection, status);
        if (status < 200 || status >= 300) {
            throw new ApiException(status, messageFromResponse(response, "Login failed."));
        }

        JSONObject json = new JSONObject(response);
        LoginResult result = new LoginResult();
        result.token = json.optString("token");
        result.installerId = json.optString("installer_id");
        result.installerName = json.optString("installer_name", "Installer");
        result.siteId = json.optString("site_id");
        result.siteName = json.optString("site_name", "Assigned site");
        result.assignedDeviceIpRange = json.optString("assigned_device_ip_range");
        if (result.token.isEmpty()) {
            throw new ApiException(status, "ONLIFI did not return an installer token.");
        }
        return result;
    }

    void uploadInstallation(String token, Installation installation) throws Exception {
        String boundary = "onlifi-installer-" + System.currentTimeMillis();
        URL url = new URL(baseUrl + "/devices");
        HttpURLConnection connection = (HttpURLConnection) url.openConnection();
        connection.setConnectTimeout(15000);
        connection.setReadTimeout(60000);
        connection.setRequestMethod("POST");
        connection.setDoOutput(true);
        connection.setRequestProperty("Authorization", "Bearer " + token);
        connection.setRequestProperty("Accept", "application/json");
        connection.setRequestProperty("Content-Type", "multipart/form-data; boundary=" + boundary);

        try (DataOutputStream out = new DataOutputStream(new BufferedOutputStream(connection.getOutputStream()))) {
            writePart(out, boundary, "local_id", installation.localId);
            writePart(out, boundary, "installer_id", installation.installerId);
            writePart(out, boundary, "device_name", installation.deviceName);
            writePart(out, boundary, "ip_address", installation.ipAddress);
            writePart(out, boundary, "latitude", String.valueOf(installation.latitude));
            writePart(out, boundary, "longitude", String.valueOf(installation.longitude));
            writePart(out, boundary, "notes", installation.notes == null ? "" : installation.notes);
            writePart(out, boundary, "created_at_device", String.valueOf(installation.createdAt));
            writeFilePart(out, boundary, "front_photo", installation.frontPhotoPath);
            writeFilePart(out, boundary, "back_photo", installation.backPhotoPath);
            out.writeBytes("--" + boundary + "--\r\n");
        }

        int status = connection.getResponseCode();
        String response = readResponse(connection, status);
        if (status < 200 || status >= 300) {
            throw new ApiException(status, messageFromResponse(response, "Upload failed."));
        }
    }

    private void writeBody(HttpURLConnection connection, String body) throws Exception {
        try (OutputStream outputStream = connection.getOutputStream()) {
            byte[] bytes = body.getBytes(StandardCharsets.UTF_8);
            outputStream.write(bytes);
        }
    }

    private void writePart(DataOutputStream out, String boundary, String name, String value) throws Exception {
        out.writeBytes("--" + boundary + "\r\n");
        out.writeBytes("Content-Disposition: form-data; name=\"" + name + "\"\r\n\r\n");
        out.write(value == null ? new byte[]{} : value.getBytes(StandardCharsets.UTF_8));
        out.writeBytes("\r\n");
    }

    private void writeFilePart(DataOutputStream out, String boundary, String name, String path) throws Exception {
        File file = new File(path);
        if (!file.exists()) {
            throw new ApiException(0, "Missing photo: " + name);
        }
        out.writeBytes("--" + boundary + "\r\n");
        out.writeBytes("Content-Disposition: form-data; name=\"" + name + "\"; filename=\"" + file.getName() + "\"\r\n");
        out.writeBytes("Content-Type: image/jpeg\r\n\r\n");
        try (InputStream inputStream = new BufferedInputStream(new FileInputStream(file))) {
            byte[] buffer = new byte[8192];
            int read;
            while ((read = inputStream.read(buffer)) != -1) {
                out.write(buffer, 0, read);
            }
        }
        out.writeBytes("\r\n");
    }

    private String readResponse(HttpURLConnection connection, int status) throws Exception {
        InputStream stream = status >= 200 && status < 400
                ? connection.getInputStream()
                : connection.getErrorStream();
        if (stream == null) {
            return "";
        }
        try (BufferedReader reader = new BufferedReader(new InputStreamReader(stream, StandardCharsets.UTF_8))) {
            StringBuilder builder = new StringBuilder();
            String line;
            while ((line = reader.readLine()) != null) {
                builder.append(line);
            }
            return builder.toString();
        }
    }

    private String messageFromResponse(String response, String fallback) {
        if (response == null || response.isEmpty()) {
            return fallback;
        }
        try {
            JSONObject json = new JSONObject(response);
            String message = json.optString("message");
            StringBuilder builder = new StringBuilder(message.isEmpty() ? fallback : message);
            JSONObject errors = json.optJSONObject("errors");
            if (errors != null) {
                java.util.Iterator<String> keys = errors.keys();
                while (keys.hasNext()) {
                    String key = keys.next();
                    builder.append("\n").append(key).append(": ");
                    Object value = errors.opt(key);
                    if (value instanceof org.json.JSONArray) {
                        org.json.JSONArray array = (org.json.JSONArray) value;
                        for (int index = 0; index < array.length(); index++) {
                            if (index > 0) {
                                builder.append(" ");
                            }
                            builder.append(array.optString(index));
                        }
                    } else {
                        builder.append(String.valueOf(value));
                    }
                }
            }
            String range = json.optString("assigned_device_ip_range");
            if (!range.isEmpty()) {
                builder.append("\nAssigned range: ").append(range);
            }
            return builder.toString();
        } catch (Exception ignored) {
            return fallback + "\nServer response: " + response;
        }
    }

    private String trimTrailingSlash(String value) {
        if (value == null || value.isEmpty()) {
            return "";
        }
        return value.endsWith("/") ? value.substring(0, value.length() - 1) : value;
    }

    static final class LoginResult {
        String token;
        String installerId;
        String installerName;
        String siteId;
        String siteName;
        String assignedDeviceIpRange;
    }

    static final class ApiException extends Exception {
        final int statusCode;

        ApiException(int statusCode, String message) {
            super(message);
            this.statusCode = statusCode;
        }
    }
}
