package net.onlifi.installer;

import android.Manifest;
import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.graphics.Typeface;
import android.location.Location;
import android.location.LocationManager;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.MediaStore;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.view.Window;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import androidx.core.content.FileProvider;

import java.io.File;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.List;
import java.util.Locale;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.regex.Pattern;

public class MainActivity extends Activity {
    private static final int COLOR_INK = Color.rgb(16, 37, 31);
    private static final int COLOR_GREEN = Color.rgb(23, 122, 85);
    private static final int COLOR_BLUE = Color.rgb(37, 99, 235);
    private static final int COLOR_GOLD = Color.rgb(242, 184, 75);
    private static final int COLOR_SURFACE = Color.rgb(246, 248, 247);
    private static final int COLOR_TEXT = Color.rgb(31, 41, 55);
    private static final int REQUEST_CAMERA = 8301;
    private static final int REQUEST_LOCATION = 8302;
    private static final int REQUEST_PHOTO = 8303;
    private static final Pattern IP_PATTERN = Pattern.compile(
            "^(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)\\." +
                    "(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)\\." +
                    "(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)\\." +
                    "(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)$");

    private InstallerStore store;
    private InstallDb db;
    private SyncClient syncClient;
    private final ExecutorService executor = Executors.newSingleThreadExecutor();

    private String screen = "login";
    private String currentPhotoSlot = "";
    private File currentPhotoFile;
    private String frontPhotoPath = "";
    private String backPhotoPath = "";
    private EditText pendingLatitudeField;
    private EditText pendingLongitudeField;
    private TextView frontPhotoStatus;
    private TextView backPhotoStatus;
    private TextView syncStatus;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        requestWindowFeature(Window.FEATURE_NO_TITLE);
        getWindow().setStatusBarColor(COLOR_INK);
        getWindow().setNavigationBarColor(COLOR_INK);

        store = new InstallerStore(this);
        db = new InstallDb(this);
        syncClient = new SyncClient(BuildConfig.API_BASE_URL);

        if (store.isLoggedIn()) {
            showDashboard();
            syncPending(true);
        } else {
            showLogin();
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (store != null && store.isLoggedIn()) {
            syncPending(true);
        }
    }

    @Override
    protected void onDestroy() {
        executor.shutdownNow();
        super.onDestroy();
    }

    @Override
    public void onBackPressed() {
        if ("add".equals(screen)) {
            showDashboard();
            return;
        }
        super.onBackPressed();
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (grantResults.length == 0 || grantResults[0] != PackageManager.PERMISSION_GRANTED) {
            toast("Permission denied.");
            return;
        }

        if (requestCode == REQUEST_CAMERA && !currentPhotoSlot.isEmpty()) {
            launchCamera(currentPhotoSlot);
        } else if (requestCode == REQUEST_LOCATION) {
            fillCoordinatesFromGps(pendingLatitudeField, pendingLongitudeField);
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQUEST_PHOTO || resultCode != RESULT_OK || currentPhotoFile == null) {
            return;
        }

        if ("front".equals(currentPhotoSlot)) {
            frontPhotoPath = currentPhotoFile.getAbsolutePath();
            if (frontPhotoStatus != null) {
                frontPhotoStatus.setText("Front photo ready");
            }
        } else if ("back".equals(currentPhotoSlot)) {
            backPhotoPath = currentPhotoFile.getAbsolutePath();
            if (backPhotoStatus != null) {
                backPhotoStatus.setText("Back photo ready");
            }
        }
    }

    private void showLogin() {
        screen = "login";
        LinearLayout page = page();
        page.addView(header("ONLIFI Installer", "Secure field installs for router tracking."));

        LinearLayout panel = panel();
        TextView title = label("Installer Login", 22, COLOR_INK, true);
        panel.addView(title);

        EditText email = field("Email", InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS);
        EditText password = field("Password", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        panel.addView(email);
        panel.addView(password);

        TextView action = button("Sign in", COLOR_GREEN);
        panel.addView(action);
        TextView hint = label("Uses " + BuildConfig.API_BASE_URL, 12, Color.rgb(107, 114, 128), false);
        hint.setPadding(0, dp(10), 0, 0);
        panel.addView(hint);
        page.addView(panel);
        setContentView(page);

        action.setOnClickListener(view -> {
            String emailValue = email.getText().toString().trim();
            String passwordValue = password.getText().toString();
            if (emailValue.isEmpty() || passwordValue.isEmpty()) {
                toast("Enter your ONLIFI installer login.");
                return;
            }
            if (!isOnline()) {
                toast("Internet is needed for the first login.");
                return;
            }
            action.setText("Signing in...");
            executor.execute(() -> {
                try {
                    SyncClient.LoginResult result = syncClient.login(emailValue, passwordValue);
                    store.saveLogin(result.token, result.installerId, result.installerName);
                    runOnUiThread(() -> {
                        toast("Welcome, " + store.getInstallerName() + ".");
                        showDashboard();
                        syncPending(true);
                    });
                } catch (Exception exception) {
                    runOnUiThread(() -> {
                        action.setText("Sign in");
                        toast(exception.getMessage());
                    });
                }
            });
        });
    }

    private void showDashboard() {
        screen = "dashboard";
        frontPhotoPath = "";
        backPhotoPath = "";

        LinearLayout page = page();
        page.addView(header("ONLIFI Installer", store.getInstallerName()));

        LinearLayout actions = row();
        actions.addView(button("Add Device", COLOR_GREEN), weightParams(1));
        actions.addView(button("Sync", COLOR_BLUE), weightParams(1));
        page.addView(actions);

        TextView addButton = (TextView) actions.getChildAt(0);
        TextView syncButton = (TextView) actions.getChildAt(1);
        addButton.setOnClickListener(view -> showAddForm());
        syncButton.setOnClickListener(view -> syncPending(false));

        LinearLayout stats = panel();
        stats.addView(label("Pending uploads", 14, Color.rgb(107, 114, 128), false));
        stats.addView(label(String.valueOf(db.pendingCount()), 44, COLOR_INK, true));
        syncStatus = label(syncLine(), 13, Color.rgb(75, 85, 99), false);
        stats.addView(syncStatus);
        page.addView(stats);

        LinearLayout listPanel = panel();
        listPanel.addView(label("Saved on this phone", 18, COLOR_INK, true));
        List<Installation> pending = db.pending();
        if (pending.isEmpty()) {
            TextView empty = label("No waiting installs.", 15, Color.rgb(107, 114, 128), false);
            empty.setPadding(0, dp(14), 0, 0);
            listPanel.addView(empty);
        } else {
            for (Installation installation : pending) {
                listPanel.addView(deviceRow(installation));
            }
        }
        page.addView(listPanel);

        TextView logout = textButton("Sign out");
        logout.setOnClickListener(view -> {
            store.clearSession();
            showLogin();
        });
        page.addView(logout);
        setContentView(scroll(page));
    }

    private void showAddForm() {
        screen = "add";
        frontPhotoPath = "";
        backPhotoPath = "";

        LinearLayout page = page();
        page.addView(header("New Device", "Saved locally first, uploaded when online."));

        LinearLayout panel = panel();
        EditText deviceName = field("Device name or router label", InputType.TYPE_CLASS_TEXT);
        EditText ipAddress = field("Unique IP address", InputType.TYPE_CLASS_PHONE);
        EditText latitude = field("Latitude", InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_DECIMAL | InputType.TYPE_NUMBER_FLAG_SIGNED);
        EditText longitude = field("Longitude", InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_DECIMAL | InputType.TYPE_NUMBER_FLAG_SIGNED);
        EditText notes = field("Install notes", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE);
        notes.setMinLines(3);

        panel.addView(deviceName);
        panel.addView(ipAddress);

        LinearLayout gpsRow = row();
        TextView gpsButton = button("Use GPS", COLOR_BLUE);
        gpsRow.addView(latitude, weightParams(1));
        gpsRow.addView(longitude, weightParams(1));
        panel.addView(gpsRow);
        panel.addView(gpsButton);

        LinearLayout photoRow = row();
        TextView frontButton = button("Capture Front", COLOR_GREEN);
        TextView backButton = button("Capture Back", COLOR_GREEN);
        photoRow.addView(frontButton, weightParams(1));
        photoRow.addView(backButton, weightParams(1));
        panel.addView(photoRow);

        frontPhotoStatus = label("Front photo required", 13, Color.rgb(107, 114, 128), false);
        backPhotoStatus = label("Back photo required", 13, Color.rgb(107, 114, 128), false);
        panel.addView(frontPhotoStatus);
        panel.addView(backPhotoStatus);
        panel.addView(notes);

        TextView save = button("Add Device", COLOR_GOLD);
        panel.addView(save);
        page.addView(panel);
        setContentView(scroll(page));

        gpsButton.setOnClickListener(view -> fillCoordinatesFromGps(latitude, longitude));
        frontButton.setOnClickListener(view -> launchCamera("front"));
        backButton.setOnClickListener(view -> launchCamera("back"));
        save.setOnClickListener(view -> saveInstallation(deviceName, ipAddress, latitude, longitude, notes));
    }

    private void saveInstallation(EditText deviceNameField, EditText ipField, EditText latField, EditText lngField, EditText notesField) {
        String deviceName = deviceNameField.getText().toString().trim();
        String ipAddress = ipField.getText().toString().trim();
        String lat = latField.getText().toString().trim();
        String lng = lngField.getText().toString().trim();
        if (deviceName.isEmpty()) {
            toast("Enter the device name.");
            return;
        }
        if (!IP_PATTERN.matcher(ipAddress).matches()) {
            toast("Enter a valid unique IPv4 address.");
            return;
        }
        if (db.ipExists(ipAddress)) {
            toast("That IP address is already waiting to upload.");
            return;
        }
        if (lat.isEmpty() || lng.isEmpty()) {
            toast("Select or enter the coordinates.");
            return;
        }
        if (frontPhotoPath.isEmpty() || backPhotoPath.isEmpty()) {
            toast("Capture both device photos.");
            return;
        }

        try {
            Installation installation = new Installation();
            installation.localId = UUID.randomUUID().toString();
            installation.installerId = store.getInstallerId();
            installation.deviceName = deviceName;
            installation.ipAddress = ipAddress;
            installation.latitude = Double.parseDouble(lat);
            installation.longitude = Double.parseDouble(lng);
            if (installation.latitude < -90 || installation.latitude > 90 ||
                    installation.longitude < -180 || installation.longitude > 180) {
                toast("Coordinates are outside the valid range.");
                return;
            }
            installation.frontPhotoPath = frontPhotoPath;
            installation.backPhotoPath = backPhotoPath;
            installation.notes = notesField.getText().toString().trim();
            installation.createdAt = System.currentTimeMillis();
            db.add(installation);
            toast(isOnline() ? "Saved. Uploading now." : "Saved offline.");
            showDashboard();
            syncPending(true);
        } catch (NumberFormatException exception) {
            toast("Coordinates must be numbers.");
        } catch (Exception exception) {
            toast("Could not save: " + exception.getMessage());
        }
    }

    private void syncPending(boolean quiet) {
        if (!store.isLoggedIn() || !isOnline() || db.pendingCount() == 0) {
            if (!quiet && !isOnline()) {
                toast("No internet connection.");
            }
            return;
        }

        if (syncStatus != null) {
            syncStatus.setText("Syncing...");
        }

        executor.execute(() -> {
            List<Installation> pending = db.pending();
            int uploaded = 0;
            for (Installation installation : pending) {
                try {
                    syncClient.uploadInstallation(store.getToken(), installation);
                    db.deleteSynced(installation);
                    uploaded++;
                } catch (Exception exception) {
                    db.saveError(installation.id, exception.getMessage());
                }
            }

            if (uploaded > 0) {
                store.saveLastSyncAt(System.currentTimeMillis());
            }
            int finalUploaded = uploaded;
            runOnUiThread(() -> {
                if (finalUploaded > 0 && !quiet) {
                    toast("Uploaded " + finalUploaded + " device(s).");
                }
                if ("dashboard".equals(screen)) {
                    showDashboard();
                }
            });
        });
    }

    private void fillCoordinatesFromGps(EditText latitude, EditText longitude) {
        pendingLatitudeField = latitude;
        pendingLongitudeField = longitude;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M &&
                checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION
            }, REQUEST_LOCATION);
            return;
        }

        LocationManager locationManager = (LocationManager) getSystemService(Context.LOCATION_SERVICE);
        if (locationManager == null) {
            toast("GPS is not available.");
            return;
        }

        Location best = null;
        for (String provider : locationManager.getProviders(true)) {
            try {
                Location location = locationManager.getLastKnownLocation(provider);
                if (location != null && (best == null || location.getAccuracy() < best.getAccuracy())) {
                    best = location;
                }
            } catch (SecurityException ignored) {
                return;
            }
        }

        if (best == null) {
            toast("No GPS fix yet. Try again outside or enter coordinates.");
            return;
        }
        if (latitude != null && longitude != null) {
            latitude.setText(String.format(Locale.US, "%.6f", best.getLatitude()));
            longitude.setText(String.format(Locale.US, "%.6f", best.getLongitude()));
        }
    }

    private void launchCamera(String slot) {
        currentPhotoSlot = slot;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M &&
                checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.CAMERA}, REQUEST_CAMERA);
            return;
        }

        Intent intent = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
        if (intent.resolveActivity(getPackageManager()) == null) {
            toast("No camera app found.");
            return;
        }

        File directory = new File(getExternalFilesDir(null), "install_photos");
        if (!directory.exists()) {
            directory.mkdirs();
        }
        currentPhotoFile = new File(directory, slot + "-" + System.currentTimeMillis() + ".jpg");
        Uri uri = FileProvider.getUriForFile(this, getPackageName() + ".fileprovider", currentPhotoFile);
        intent.putExtra(MediaStore.EXTRA_OUTPUT, uri);
        intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_GRANT_WRITE_URI_PERMISSION);
        startActivityForResult(intent, REQUEST_PHOTO);
    }

    private boolean isOnline() {
        ConnectivityManager manager = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
        if (manager == null) {
            return true;
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            Network network = manager.getActiveNetwork();
            if (network == null) {
                return false;
            }
            NetworkCapabilities capabilities = manager.getNetworkCapabilities(network);
            return capabilities != null &&
                    capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
        }
        android.net.NetworkInfo info = manager.getActiveNetworkInfo();
        return info != null && info.isConnected();
    }

    private String syncLine() {
        if (!isOnline()) {
            return "Offline. Uploads will retry later.";
        }
        long lastSync = store.getLastSyncAt();
        if (lastSync == 0L) {
            return "Online. No completed upload yet.";
        }
        return "Last upload " + new SimpleDateFormat("MMM d, h:mm a", Locale.US).format(new Date(lastSync));
    }

    private View header(String title, String subtitle) {
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.VERTICAL);
        header.setPadding(dp(22), dp(28), dp(22), dp(24));
        header.setBackgroundColor(COLOR_INK);
        TextView titleView = label(title, 28, Color.WHITE, true);
        TextView subtitleView = label(subtitle, 14, Color.rgb(220, 235, 228), false);
        subtitleView.setPadding(0, dp(6), 0, 0);
        header.addView(titleView);
        header.addView(subtitleView);
        return header;
    }

    private LinearLayout page() {
        LinearLayout page = new LinearLayout(this);
        page.setOrientation(LinearLayout.VERTICAL);
        page.setBackgroundColor(COLOR_SURFACE);
        page.setPadding(0, 0, 0, dp(18));
        return page;
    }

    private ScrollView scroll(View content) {
        ScrollView scrollView = new ScrollView(this);
        scrollView.setFillViewport(true);
        scrollView.setBackgroundColor(COLOR_SURFACE);
        scrollView.addView(content);
        return scrollView;
    }

    private LinearLayout panel() {
        LinearLayout panel = new LinearLayout(this);
        panel.setOrientation(LinearLayout.VERTICAL);
        panel.setPadding(dp(18), dp(18), dp(18), dp(18));
        panel.setBackground(rounded(Color.WHITE, dp(8), Color.rgb(229, 231, 235), dp(1)));
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        params.setMargins(dp(14), dp(14), dp(14), 0);
        panel.setLayoutParams(params);
        return panel;
    }

    private LinearLayout row() {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER);
        row.setPadding(dp(14), dp(14), dp(14), 0);
        return row;
    }

    private TextView deviceRow(Installation installation) {
        String text = installation.deviceName + "\n" + installation.ipAddress + "  " +
                String.format(Locale.US, "%.5f, %.5f", installation.latitude, installation.longitude);
        if (installation.lastError != null && !installation.lastError.isEmpty()) {
            text += "\n" + installation.lastError;
        }
        TextView row = label(text, 14, COLOR_TEXT, false);
        row.setPadding(0, dp(12), 0, dp(12));
        return row;
    }

    private EditText field(String hint, int inputType) {
        EditText editText = new EditText(this);
        editText.setHint(hint);
        editText.setTextColor(COLOR_TEXT);
        editText.setHintTextColor(Color.rgb(107, 114, 128));
        editText.setTextSize(15);
        editText.setInputType(inputType);
        editText.setSingleLine((inputType & InputType.TYPE_TEXT_FLAG_MULTI_LINE) == 0);
        editText.setPadding(dp(14), 0, dp(14), 0);
        editText.setBackground(rounded(Color.WHITE, dp(8), Color.rgb(209, 213, 219), dp(1)));
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                dp(52));
        params.setMargins(0, dp(12), 0, 0);
        editText.setLayoutParams(params);
        return editText;
    }

    private TextView button(String text, int color) {
        TextView button = label(text, 15, Color.WHITE, true);
        button.setGravity(Gravity.CENTER);
        button.setMinHeight(dp(52));
        button.setPadding(dp(14), 0, dp(14), 0);
        button.setBackground(rounded(color, dp(8), color, 0));
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                dp(52));
        params.setMargins(0, dp(14), 0, 0);
        button.setLayoutParams(params);
        button.setClickable(true);
        return button;
    }

    private TextView textButton(String text) {
        TextView button = label(text, 15, COLOR_BLUE, true);
        button.setGravity(Gravity.CENTER);
        button.setPadding(dp(14), dp(18), dp(14), dp(18));
        button.setClickable(true);
        return button;
    }

    private TextView label(String text, int sizeSp, int color, boolean bold) {
        TextView view = new TextView(this);
        view.setText(text);
        view.setTextSize(sizeSp);
        view.setTextColor(color);
        view.setLineSpacing(dp(2), 1.0f);
        view.setIncludeFontPadding(true);
        if (bold) {
            view.setTypeface(Typeface.DEFAULT_BOLD);
        }
        return view;
    }

    private LinearLayout.LayoutParams weightParams(int weight) {
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, weight);
        params.setMargins(dp(4), 0, dp(4), 0);
        return params;
    }

    private android.graphics.drawable.GradientDrawable rounded(int fill, int radius, int stroke, int strokeWidth) {
        android.graphics.drawable.GradientDrawable drawable = new android.graphics.drawable.GradientDrawable();
        drawable.setColor(fill);
        drawable.setCornerRadius(radius);
        if (strokeWidth > 0) {
            drawable.setStroke(strokeWidth, stroke);
        }
        return drawable;
    }

    private void toast(String message) {
        Toast.makeText(this, message, Toast.LENGTH_LONG).show();
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
