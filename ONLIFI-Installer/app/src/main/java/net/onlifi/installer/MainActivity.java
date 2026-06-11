package net.onlifi.installer;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.ClipData;
import android.content.ClipboardManager;
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
    private static final int INK = Color.rgb(20, 24, 31);
    private static final int FOREST = Color.rgb(18, 111, 83);
    private static final int BLUE = Color.rgb(37, 99, 235);
    private static final int GOLD = Color.rgb(232, 169, 51);
    private static final int SURFACE = Color.rgb(244, 247, 246);
    private static final int TEXT = Color.rgb(38, 48, 57);
    private static final int MUTED = Color.rgb(105, 116, 128);
    private static final int LINE = Color.rgb(224, 229, 226);
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

    private String tab = "home";
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
        getWindow().setStatusBarColor(INK);
        getWindow().setNavigationBarColor(Color.WHITE);

        store = new InstallerStore(this);
        db = new InstallDb(this);
        syncClient = new SyncClient(BuildConfig.API_BASE_URL);

        if (store.isLoggedIn()) {
            showHome();
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
        if (store != null && store.isLoggedIn() && !"home".equals(tab)) {
            showHome();
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

        try {
            PhotoOptimizer.optimize(currentPhotoFile);
        } catch (Exception exception) {
            toast("Photo saved, but compression failed: " + exception.getMessage());
        }

        if ("front".equals(currentPhotoSlot)) {
            frontPhotoPath = currentPhotoFile.getAbsolutePath();
            if (frontPhotoStatus != null) {
                frontPhotoStatus.setText("Front photo ready (" + fileSize(currentPhotoFile) + ")");
                frontPhotoStatus.setTextColor(FOREST);
            }
        } else if ("back".equals(currentPhotoSlot)) {
            backPhotoPath = currentPhotoFile.getAbsolutePath();
            if (backPhotoStatus != null) {
                backPhotoStatus.setText("Back photo ready (" + fileSize(currentPhotoFile) + ")");
                backPhotoStatus.setTextColor(FOREST);
            }
        }
    }

    private void showLogin() {
        tab = "login";
        LinearLayout page = page();
        page.setPadding(0, 0, 0, 0);
        page.addView(hero("ONLIFI Installer", "Field installs, device evidence, and offline sync."));

        LinearLayout form = card();
        form.addView(label("Installer sign in", 22, INK, true));
        form.addView(label("Use the installer account assigned to your ONLIFI site.", 14, MUTED, false));

        EditText email = field("Email address", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS);
        EditText password = field("Password", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        form.addView(email);
        form.addView(password);

        TextView action = primaryButton("Sign in", FOREST);
        TextView api = smallText("API: " + BuildConfig.API_BASE_URL);
        form.addView(action);
        form.addView(api);
        page.addView(form);
        setContentView(scroll(page));

        action.setOnClickListener(view -> {
            String emailValue = email.getText().toString().trim();
            String passwordValue = password.getText().toString();
            if (emailValue.isEmpty() || passwordValue.isEmpty()) {
                toast("Enter your installer login.");
                return;
            }
            if (!isOnline()) {
                toast("Internet is needed for first login.");
                return;
            }
            action.setText("Signing in...");
            executor.execute(() -> {
                try {
                    SyncClient.LoginResult result = syncClient.login(emailValue, passwordValue);
                    store.saveLogin(
                            result.token,
                            result.installerId,
                            result.installerName,
                            result.siteId,
                            result.siteName,
                            result.assignedDeviceIpRange
                    );
                    runOnUiThread(() -> {
                        toast("Welcome, " + store.getInstallerName() + ".");
                        showHome();
                        syncPending(true);
                    });
                } catch (Exception exception) {
                    runOnUiThread(() -> {
                        action.setText("Sign in");
                        showProblem("Login failed", exception.getMessage());
                    });
                }
            });
        });
    }

    private void showHome() {
        tab = "home";
        LinearLayout body = page();
        body.addView(statusBand());

        LinearLayout stats = new LinearLayout(this);
        stats.setOrientation(LinearLayout.HORIZONTAL);
        stats.setPadding(dp(14), 0, dp(14), 0);
        stats.addView(metric("Pending", String.valueOf(db.pendingCount()), FOREST), weightParams(1));
        stats.addView(metric("Failed", String.valueOf(db.failedCount()), GOLD), weightParams(1));
        stats.addView(metric("Uploaded", String.valueOf(store.getTotalUploaded()), BLUE), weightParams(1));
        body.addView(stats);

        LinearLayout quick = card();
        quick.addView(label("Today", 18, INK, true));
        quick.addView(label("Assigned site: " + store.getSiteName(), 14, TEXT, false));
        quick.addView(label(ipRangeLine(), 14, MUTED, false));
        syncStatus = label(syncLine(), 14, MUTED, false);
        syncStatus.setPadding(0, dp(10), 0, 0);
        quick.addView(syncStatus);

        LinearLayout actions = actionRow();
        TextView add = smallButton("Add device", FOREST);
        TextView retry = smallButton("Retry sync", BLUE);
        actions.addView(add, weightParams(1));
        actions.addView(retry, weightParams(1));
        quick.addView(actions);
        body.addView(quick);

        String error = db.latestError();
        if (!error.isEmpty()) {
            LinearLayout problem = card();
            problem.setBackground(rounded(Color.rgb(255, 252, 236), dp(8), Color.rgb(245, 208, 107), dp(1)));
            problem.addView(label("Latest upload issue", 17, INK, true));
            problem.addView(label(error, 14, TEXT, false));
            TextView viewQueue = textButton("Open Queue");
            viewQueue.setOnClickListener(view -> showQueue());
            problem.addView(viewQueue);
            body.addView(problem);
        }

        add.setOnClickListener(view -> showAdd());
        retry.setOnClickListener(view -> syncPending(false));
        setContentView(shell(body));
    }

    private void showAdd() {
        tab = "add";
        frontPhotoPath = "";
        backPhotoPath = "";

        LinearLayout body = page();
        body.addView(sectionTitle("Add Device", "Capture the router details before leaving the site."));

        LinearLayout form = card();
        EditText deviceName = field("Device name or router label", InputType.TYPE_CLASS_TEXT);
        EditText ipAddress = field("Unique IP address", InputType.TYPE_CLASS_PHONE);
        EditText latitude = field("Latitude", InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_DECIMAL | InputType.TYPE_NUMBER_FLAG_SIGNED);
        EditText longitude = field("Longitude", InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_FLAG_DECIMAL | InputType.TYPE_NUMBER_FLAG_SIGNED);
        EditText notes = field("Install notes", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE);
        notes.setMinLines(3);
        notes.setGravity(Gravity.TOP);

        form.addView(deviceName);
        form.addView(ipAddress);

        LinearLayout coordinateRow = actionRow();
        coordinateRow.addView(latitude, weightParams(1));
        coordinateRow.addView(longitude, weightParams(1));
        form.addView(coordinateRow);

        TextView gps = smallButton("Use current GPS", BLUE);
        form.addView(gps);

        LinearLayout photoRow = actionRow();
        TextView front = smallButton("Front photo", FOREST);
        TextView back = smallButton("Back photo", FOREST);
        photoRow.addView(front, weightParams(1));
        photoRow.addView(back, weightParams(1));
        form.addView(photoRow);

        frontPhotoStatus = smallText("Front photo required");
        backPhotoStatus = smallText("Back photo required");
        form.addView(frontPhotoStatus);
        form.addView(backPhotoStatus);
        form.addView(notes);

        TextView save = primaryButton("Save Device", INK);
        form.addView(save);
        body.addView(form);

        gps.setOnClickListener(view -> fillCoordinatesFromGps(latitude, longitude));
        front.setOnClickListener(view -> launchCamera("front"));
        back.setOnClickListener(view -> launchCamera("back"));
        save.setOnClickListener(view -> saveInstallation(deviceName, ipAddress, latitude, longitude, notes));
        setContentView(shell(body));
    }

    private void showQueue() {
        tab = "queue";
        LinearLayout body = page();
        body.addView(sectionTitle("Upload Queue", "Pending records stay here until ONLIFI confirms upload."));

        LinearLayout actions = actionRow();
        TextView retry = smallButton("Retry all", BLUE);
        TextView add = smallButton("Add another", FOREST);
        actions.addView(retry, weightParams(1));
        actions.addView(add, weightParams(1));
        body.addView(actions);

        List<Installation> pending = db.pending();
        if (pending.isEmpty()) {
            LinearLayout empty = card();
            empty.addView(label("Queue is clear", 20, INK, true));
            empty.addView(label("Uploaded installs are removed from this phone automatically.", 14, MUTED, false));
            body.addView(empty);
        } else {
            for (Installation installation : pending) {
                body.addView(queueCard(installation));
            }
        }

        retry.setOnClickListener(view -> syncPending(false));
        add.setOnClickListener(view -> showAdd());
        setContentView(shell(body));
    }

    private void showAccount() {
        tab = "account";
        LinearLayout body = page();
        body.addView(sectionTitle("Account", "Installer access and diagnostics."));

        LinearLayout account = card();
        account.addView(label(store.getInstallerName(), 22, INK, true));
        account.addView(label("Site: " + store.getSiteName(), 15, TEXT, false));
        account.addView(label(ipRangeLine(), 15, MUTED, false));
        account.addView(label("Pending: " + db.pendingCount() + "   Failed: " + db.failedCount(), 15, TEXT, false));
        account.addView(label("API: " + BuildConfig.API_BASE_URL, 13, MUTED, false));
        account.addView(label("Last sync: " + store.getLastSyncMessage(), 13, MUTED, false));

        LinearLayout actions = actionRow();
        TextView copy = smallButton("Copy diagnostics", BLUE);
        TextView signOut = smallButton("Sign out", INK);
        actions.addView(copy, weightParams(1));
        actions.addView(signOut, weightParams(1));
        account.addView(actions);
        body.addView(account);

        copy.setOnClickListener(view -> copyDiagnostics());
        signOut.setOnClickListener(view -> {
            store.clearSession();
            showLogin();
        });
        setContentView(shell(body));
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
            toast("Enter a valid IPv4 address.");
            return;
        }
        if (!store.getIpRange().isEmpty() && !ipInCidr(ipAddress, store.getIpRange())) {
            toast("IP is outside assigned range: " + store.getIpRange());
            return;
        }
        if (db.ipExists(ipAddress)) {
            toast("That IP address is already in the queue.");
            return;
        }
        if (lat.isEmpty() || lng.isEmpty()) {
            toast("Select or enter coordinates.");
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
            showQueue();
            syncPending(true);
        } catch (NumberFormatException exception) {
            toast("Coordinates must be numbers.");
        } catch (Exception exception) {
            showProblem("Could not save", exception.getMessage());
        }
    }

    private void syncPending(boolean quiet) {
        if (!store.isLoggedIn()) {
            return;
        }
        if (!isOnline()) {
            if (!quiet) {
                toast("No internet connection.");
            }
            store.saveSyncMessage("Offline. Uploads will retry.");
            return;
        }
        if (db.pendingCount() == 0) {
            if (!quiet) {
                toast("Queue is clear.");
            }
            store.saveSyncMessage("Queue is clear.");
            return;
        }

        if (syncStatus != null) {
            syncStatus.setText("Syncing...");
        }

        executor.execute(() -> {
            List<Installation> pending = db.pending();
            int uploaded = 0;
            String lastError = "";
            for (Installation installation : pending) {
                try {
                    syncClient.uploadInstallation(store.getToken(), installation);
                    db.deleteSynced(installation);
                    uploaded++;
                } catch (Exception exception) {
                    lastError = exception.getMessage();
                    db.saveError(installation.id, lastError);
                }
            }

            String message = uploaded > 0
                    ? "Uploaded " + uploaded + " device(s)."
                    : (lastError.isEmpty() ? "No uploads completed." : lastError);
            store.saveSyncResult(System.currentTimeMillis(), message, uploaded);
            int finalUploaded = uploaded;
            String finalLastError = lastError;
            runOnUiThread(() -> {
                if (!quiet) {
                    if (finalUploaded > 0) {
                        toast("Uploaded " + finalUploaded + " device(s).");
                    } else if (!finalLastError.isEmpty()) {
                        showProblem("Upload failed", finalLastError);
                    }
                }
                refreshCurrentTab();
            });
        });
    }

    private void refreshCurrentTab() {
        if ("home".equals(tab)) {
            showHome();
        } else if ("queue".equals(tab)) {
            showQueue();
        } else if ("account".equals(tab)) {
            showAccount();
        }
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
        latitude.setText(String.format(Locale.US, "%.6f", best.getLatitude()));
        longitude.setText(String.format(Locale.US, "%.6f", best.getLongitude()));
        toast("Coordinates added.");
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

    private View shell(LinearLayout body) {
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(SURFACE);
        root.addView(topBar(), new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT));
        root.addView(scroll(body), new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                0,
                1));
        root.addView(tabBar(), new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT));
        return root;
    }

    private View topBar() {
        LinearLayout top = new LinearLayout(this);
        top.setOrientation(LinearLayout.VERTICAL);
        top.setPadding(dp(20), dp(18), dp(20), dp(16));
        top.setBackgroundColor(INK);
        top.addView(label("ONLIFI Installer", 24, Color.WHITE, true));
        top.addView(label(store.getSiteName(), 14, Color.rgb(205, 214, 211), false));
        return top;
    }

    private View tabBar() {
        LinearLayout tabs = new LinearLayout(this);
        tabs.setOrientation(LinearLayout.HORIZONTAL);
        tabs.setPadding(dp(10), dp(8), dp(10), dp(10));
        tabs.setBackgroundColor(Color.WHITE);
        tabs.addView(tabButton("Home", "home"), weightParams(1));
        tabs.addView(tabButton("Add", "add"), weightParams(1));
        tabs.addView(tabButton("Queue", "queue"), weightParams(1));
        tabs.addView(tabButton("Account", "account"), weightParams(1));
        return tabs;
    }

    private TextView tabButton(String text, String target) {
        boolean active = target.equals(tab);
        TextView button = label(text, 13, active ? Color.WHITE : MUTED, true);
        button.setGravity(Gravity.CENTER);
        button.setPadding(dp(8), dp(10), dp(8), dp(10));
        button.setBackground(rounded(active ? INK : Color.TRANSPARENT, dp(8), active ? INK : Color.TRANSPARENT, 0));
        button.setOnClickListener(view -> {
            if ("home".equals(target)) {
                showHome();
            } else if ("add".equals(target)) {
                showAdd();
            } else if ("queue".equals(target)) {
                showQueue();
            } else {
                showAccount();
            }
        });
        return button;
    }

    private View hero(String title, String subtitle) {
        LinearLayout hero = new LinearLayout(this);
        hero.setOrientation(LinearLayout.VERTICAL);
        hero.setPadding(dp(24), dp(44), dp(24), dp(34));
        hero.setBackgroundColor(INK);
        hero.addView(label(title, 31, Color.WHITE, true));
        TextView copy = label(subtitle, 15, Color.rgb(211, 220, 216), false);
        copy.setPadding(0, dp(8), 0, 0);
        hero.addView(copy);
        return hero;
    }

    private View sectionTitle(String title, String subtitle) {
        LinearLayout section = new LinearLayout(this);
        section.setOrientation(LinearLayout.VERTICAL);
        section.setPadding(dp(18), dp(18), dp(18), dp(2));
        section.addView(label(title, 24, INK, true));
        section.addView(label(subtitle, 14, MUTED, false));
        return section;
    }

    private View statusBand() {
        LinearLayout band = new LinearLayout(this);
        band.setOrientation(LinearLayout.VERTICAL);
        band.setPadding(dp(18), dp(16), dp(18), dp(4));
        TextView status = label(isOnline() ? "Online" : "Offline mode", 13, isOnline() ? FOREST : GOLD, true);
        TextView subtitle = label(isOnline() ? "Uploads can run now." : "New devices will stay safely on this phone.", 14, MUTED, false);
        band.addView(status);
        band.addView(subtitle);
        return band;
    }

    private LinearLayout page() {
        LinearLayout page = new LinearLayout(this);
        page.setOrientation(LinearLayout.VERTICAL);
        page.setBackgroundColor(SURFACE);
        page.setPadding(0, 0, 0, dp(18));
        return page;
    }

    private ScrollView scroll(View content) {
        ScrollView scrollView = new ScrollView(this);
        scrollView.setFillViewport(false);
        scrollView.setBackgroundColor(SURFACE);
        scrollView.addView(content);
        return scrollView;
    }

    private LinearLayout card() {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(16), dp(16), dp(16), dp(16));
        card.setBackground(rounded(Color.WHITE, dp(8), LINE, dp(1)));
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT);
        params.setMargins(dp(14), dp(12), dp(14), 0);
        card.setLayoutParams(params);
        return card;
    }

    private TextView metric(String label, String value, int color) {
        TextView metric = label(value + "\n" + label, 14, color, true);
        metric.setGravity(Gravity.CENTER);
        metric.setPadding(dp(8), dp(14), dp(8), dp(14));
        metric.setBackground(rounded(Color.WHITE, dp(8), LINE, dp(1)));
        return metric;
    }

    private View queueCard(Installation installation) {
        LinearLayout card = card();
        card.addView(label(installation.deviceName, 18, INK, true));
        card.addView(label(installation.ipAddress + "  |  " +
                String.format(Locale.US, "%.5f, %.5f", installation.latitude, installation.longitude), 14, TEXT, false));
        card.addView(label("Photos: front and back captured", 13, MUTED, false));
        if (installation.lastError != null && !installation.lastError.isEmpty()) {
            TextView error = label(installation.lastError, 13, Color.rgb(153, 83, 8), false);
            error.setPadding(0, dp(8), 0, 0);
            card.addView(error);
        } else {
            card.addView(label("Waiting for upload", 13, MUTED, false));
        }
        return card;
    }

    private EditText field(String hint, int inputType) {
        EditText editText = new EditText(this);
        editText.setHint(hint);
        editText.setTextColor(TEXT);
        editText.setHintTextColor(MUTED);
        editText.setTextSize(15);
        editText.setInputType(inputType);
        editText.setSingleLine((inputType & InputType.TYPE_TEXT_FLAG_MULTI_LINE) == 0);
        editText.setPadding(dp(13), 0, dp(13), 0);
        editText.setBackground(rounded(Color.rgb(250, 252, 251), dp(8), LINE, dp(1)));
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                dp((inputType & InputType.TYPE_TEXT_FLAG_MULTI_LINE) == 0 ? 52 : 96));
        params.setMargins(0, dp(10), 0, 0);
        editText.setLayoutParams(params);
        return editText;
    }

    private LinearLayout actionRow() {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER);
        row.setPadding(0, dp(8), 0, 0);
        return row;
    }

    private TextView primaryButton(String text, int color) {
        TextView button = label(text, 15, Color.WHITE, true);
        button.setGravity(Gravity.CENTER);
        button.setMinHeight(dp(52));
        button.setPadding(dp(14), 0, dp(14), 0);
        button.setBackground(rounded(color, dp(8), color, 0));
        button.setClickable(true);
        button.setLayoutParams(buttonParams());
        return button;
    }

    private TextView smallButton(String text, int color) {
        TextView button = label(text, 14, Color.WHITE, true);
        button.setGravity(Gravity.CENTER);
        button.setMinHeight(dp(46));
        button.setPadding(dp(10), 0, dp(10), 0);
        button.setBackground(rounded(color, dp(8), color, 0));
        button.setClickable(true);
        button.setLayoutParams(buttonParams());
        return button;
    }

    private TextView textButton(String text) {
        TextView button = label(text, 14, BLUE, true);
        button.setPadding(0, dp(12), 0, 0);
        button.setClickable(true);
        return button;
    }

    private TextView smallText(String text) {
        TextView view = label(text, 12, MUTED, false);
        view.setPadding(0, dp(8), 0, 0);
        return view;
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

    private LinearLayout.LayoutParams buttonParams() {
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                dp(48));
        params.setMargins(0, dp(12), 0, 0);
        return params;
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
            return capabilities != null && capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
        }
        android.net.NetworkInfo info = manager.getActiveNetworkInfo();
        return info != null && info.isConnected();
    }

    private String syncLine() {
        long lastSync = store.getLastSyncAt();
        if (!isOnline()) {
            return "Offline. Uploads will retry later.";
        }
        if (lastSync == 0L) {
            return store.getLastSyncMessage();
        }
        return store.getLastSyncMessage() + "\n" +
                new SimpleDateFormat("MMM d, h:mm a", Locale.US).format(new Date(lastSync));
    }

    private String ipRangeLine() {
        return store.getIpRange().isEmpty()
                ? "No assigned IP range returned by ONLIFI."
                : "Assigned IP range: " + store.getIpRange();
    }

    private void copyDiagnostics() {
        String diagnostics = "ONLIFI Installer\n" +
                "API: " + BuildConfig.API_BASE_URL + "\n" +
                "Installer: " + store.getInstallerName() + "\n" +
                "Site: " + store.getSiteName() + "\n" +
                "IP range: " + store.getIpRange() + "\n" +
                "Pending: " + db.pendingCount() + "\n" +
                "Failed: " + db.failedCount() + "\n" +
                "Last sync: " + store.getLastSyncMessage() + "\n" +
                "Latest error: " + db.latestError();
        ClipboardManager clipboard = (ClipboardManager) getSystemService(CLIPBOARD_SERVICE);
        if (clipboard != null) {
            clipboard.setPrimaryClip(ClipData.newPlainText("ONLIFI Installer diagnostics", diagnostics));
            toast("Diagnostics copied.");
        }
    }

    private boolean ipInCidr(String ip, String cidr) {
        if (cidr == null || cidr.isEmpty()) {
            return true;
        }
        if (!cidr.contains("/")) {
            return ip.equals(cidr);
        }
        String[] parts = cidr.split("/", 2);
        try {
            long ipLong = ipToLong(ip);
            long subnet = ipToLong(parts[0]);
            int bits = Integer.parseInt(parts[1]);
            if (bits < 0 || bits > 32) {
                return false;
            }
            long mask = bits == 0 ? 0 : 0xFFFFFFFFL << (32 - bits);
            return (ipLong & mask) == (subnet & mask);
        } catch (Exception ignored) {
            return true;
        }
    }

    private long ipToLong(String ip) {
        String[] parts = ip.split("\\.");
        long result = 0;
        for (String part : parts) {
            result = (result << 8) + Integer.parseInt(part);
        }
        return result & 0xFFFFFFFFL;
    }

    private String fileSize(File file) {
        long kb = Math.max(1, file.length() / 1024);
        return kb >= 1024 ? String.format(Locale.US, "%.1f MB", kb / 1024f) : kb + " KB";
    }

    private void showProblem(String title, String message) {
        new AlertDialog.Builder(this)
                .setTitle(title)
                .setMessage(message == null || message.isEmpty() ? "No additional details returned." : message)
                .setPositiveButton("OK", null)
                .show();
    }

    private void toast(String message) {
        Toast.makeText(this, message, Toast.LENGTH_LONG).show();
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
