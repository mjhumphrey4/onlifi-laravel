package net.onlifi.manager;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.Manifest;
import android.app.KeyguardManager;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.net.ConnectivityManager;
import android.net.NetworkInfo;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.provider.Settings;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.widget.AdapterView;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.HorizontalScrollView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.ScrollView;
import android.widget.Spinner;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.text.NumberFormat;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class MainActivity extends Activity {
    private static final int REQUEST_UNLOCK = 7001;
    private static final int REQUEST_DOWNLOAD_PERMISSIONS = 7002;
    private static final String API_BASE = BuildConfig.ONLIFI_API_BASE_URL;
    private static final String PREFS_NAME = "onlifi_manager_native";
    private static final String PREF_TOKEN = "tenant_token";
    private static final String PREF_USER = "tenant_user";
    private static final String PREF_SELECTED_SITE = "selected_site_id";
    private static final String PREF_BACKGROUND_AT = "background_at";
    private static final long LOCK_AFTER_BACKGROUND_MS = 5_000L;
    private static final long AUTO_REFRESH_MS = 60_000L;

    private static final int COLOR_BG = Color.rgb(10, 22, 40);
    private static final int COLOR_CARD = Color.rgb(30, 58, 95);
    private static final int COLOR_CARD_SOFT = Color.rgb(26, 47, 74);
    private static final int COLOR_INK = Color.rgb(232, 244, 248);
    private static final int COLOR_MUTED = Color.rgb(148, 163, 184);
    private static final int COLOR_PRIMARY = Color.rgb(16, 185, 129);
    private static final int COLOR_MINT = Color.rgb(52, 211, 153);
    private static final int COLOR_BLUE = Color.rgb(59, 130, 246);
    private static final int COLOR_BORDER = Color.argb(60, 52, 211, 153);
    private static final int COLOR_ORANGE = Color.rgb(245, 158, 11);
    private static final int COLOR_WARN = Color.rgb(249, 115, 22);

    private final ExecutorService executor = Executors.newFixedThreadPool(4);
    private final android.os.Handler mainHandler = new android.os.Handler();
    private final List<Site> sites = new ArrayList<>();
    private final List<VoucherType> voucherTypes = new ArrayList<>();
    private final List<SalesPoint> salesPoints = new ArrayList<>();

    private SharedPreferences preferences;
    private FrameLayout root;
    private LinearLayout appShell;
    private LinearLayout content;
    private LinearLayout bottomNav;
    private ProgressBar loadingBar;
    private Spinner siteSpinner;

    private String token;
    private int selectedSiteId;
    private String userName = "ONLIFI user";
    private String activeTab = "home";
    private boolean unlockedForThisRun;
    private boolean unlockPromptActive;
    private boolean appStarted;

    private final Runnable autoRefresh = new Runnable() {
        @Override
        public void run() {
            if (appStarted && token != null && "home".equals(activeTab)) {
                loadDashboard(false);
            }
            mainHandler.postDelayed(this, AUTO_REFRESH_MS);
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        preferences = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        token = preferences.getString(PREF_TOKEN, null);
        selectedSiteId = preferences.getInt(PREF_SELECTED_SITE, 0);
        userName = readUserName();
        unlockedForThisRun = token == null;

        configureWindow();
        requestDownloadPermissionsIfNeeded();
        root = new FrameLayout(this);
        root.setBackgroundColor(COLOR_BG);
        setContentView(root);

        if (token == null) {
            showLogin(false, null);
        } else {
            showUnlockScreen();
            promptForDeviceCredential();
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (!appStarted || token == null) return;

        long backgroundAt = preferences.getLong(PREF_BACKGROUND_AT, 0L);
        boolean needsLock = backgroundAt > 0L
                && System.currentTimeMillis() - backgroundAt > LOCK_AFTER_BACKGROUND_MS;

        if (!unlockedForThisRun || needsLock) {
            unlockedForThisRun = false;
            showUnlockScreen();
            promptForDeviceCredential();
        }
    }

    @Override
    protected void onStop() {
        super.onStop();
        if (token != null) {
            preferences.edit().putLong(PREF_BACKGROUND_AT, System.currentTimeMillis()).apply();
        }
    }

    @Override
    protected void onDestroy() {
        mainHandler.removeCallbacks(autoRefresh);
        executor.shutdownNow();
        super.onDestroy();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQUEST_UNLOCK) return;

        unlockPromptActive = false;
        if (resultCode == RESULT_OK) {
            unlockedForThisRun = true;
            preferences.edit().remove(PREF_BACKGROUND_AT).apply();
            startApp();
        } else {
            showUnlockScreen();
            moveTaskToBack(true);
        }
    }

    private void configureWindow() {
        requestWindowFeature(Window.FEATURE_NO_TITLE);
        Window window = getWindow();
        window.setStatusBarColor(COLOR_BG);
        window.setNavigationBarColor(COLOR_CARD_SOFT);
        window.addFlags(WindowManager.LayoutParams.FLAG_SECURE);
    }

    private void showLogin(boolean twoFactorRequired, String twoFactorToken) {
        appStarted = false;
        root.removeAllViews();

        ScrollView scroll = new ScrollView(this);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER_HORIZONTAL);
        box.setPadding(dp(24), dp(40), dp(24), dp(24));
        scroll.addView(box);

        TextView badge = text("ON", 28, COLOR_BG, true);
        badge.setGravity(Gravity.CENTER);
        badge.setBackgroundResource(R.drawable.onlifi_badge);
        LinearLayout.LayoutParams badgeParams = new LinearLayout.LayoutParams(dp(84), dp(84));
        box.addView(badge, badgeParams);

        TextView title = text("ONLIFI-Manager", 28, COLOR_INK, true);
        title.setGravity(Gravity.CENTER);
        title.setPadding(0, dp(20), 0, dp(4));
        box.addView(title, matchWrap());

        TextView subtitle = text("Sign in once. Your phone lock protects future access.", 15, COLOR_MUTED, false);
        subtitle.setGravity(Gravity.CENTER);
        subtitle.setPadding(0, 0, 0, dp(24));
        box.addView(subtitle, matchWrap());

        LinearLayout card = card();
        box.addView(card, matchWrap());

        EditText login = input("Username or email", false);
        EditText password = input("Password", true);
        EditText twoFactor = input("Authenticator code", false);
        twoFactor.setInputType(InputType.TYPE_CLASS_NUMBER);
        twoFactor.setVisibility(twoFactorRequired ? View.VISIBLE : View.GONE);

        card.addView(label("Username or Email"));
        card.addView(login, matchWrapMargin(0, 0, 0, dp(14)));
        card.addView(label("Password"));
        card.addView(password, matchWrapMargin(0, 0, 0, dp(14)));
        card.addView(twoFactor, matchWrapMargin(0, 0, 0, dp(14)));

        Button submit = primaryButton(twoFactorRequired ? "Verify and Sign In" : "Sign In");
        card.addView(submit, matchWrap());

        submit.setOnClickListener(v -> {
            String loginValue = login.getText().toString().trim();
            String passwordValue = password.getText().toString();
            String codeValue = twoFactor.getText().toString().trim();

            if (loginValue.isEmpty() || passwordValue.isEmpty()) {
                toast("Enter your ONLIFI login details.");
                return;
            }
            if (twoFactorRequired && codeValue.isEmpty()) {
                toast("Enter the authenticator code.");
                return;
            }

            submit.setEnabled(false);
            submit.setText("Signing in...");
            executor.execute(() -> {
                try {
                    JSONObject payload = new JSONObject()
                            .put("login", loginValue)
                            .put("email", loginValue)
                            .put("password", passwordValue);
                    if (twoFactorRequired) {
                        payload.put("two_factor_code", codeValue);
                        payload.put("two_factor_token", twoFactorToken);
                    }

                    JSONObject response = request("POST", "/tenant/login", payload, false, 0);
                    if (response.optBoolean("requires_2fa")) {
                        String nextToken = response.optString("two_factor_token", "");
                        mainHandler.post(() -> showLogin(true, nextToken));
                        return;
                    }

                    token = response.optString("token", "");
                    if (token.isEmpty()) throw new Exception("Login response did not include a token.");

                    JSONObject user = response.optJSONObject("user");
                    if (user != null) {
                        userName = user.optString("name", user.optString("email", userName));
                    }
                    preferences.edit()
                            .putString(PREF_TOKEN, token)
                            .putString(PREF_USER, user == null ? "{}" : user.toString())
                            .remove(PREF_BACKGROUND_AT)
                            .apply();

                    unlockedForThisRun = true;
                    mainHandler.post(this::startApp);
                } catch (Exception e) {
                    mainHandler.post(() -> {
                        submit.setEnabled(true);
                        submit.setText(twoFactorRequired ? "Verify and Sign In" : "Sign In");
                        alert("Login failed", e.getMessage());
                    });
                }
            });
        });

        root.addView(scroll);
    }

    private void showUnlockScreen() {
        root.removeAllViews();
        LinearLayout overlay = new LinearLayout(this);
        overlay.setOrientation(LinearLayout.VERTICAL);
        overlay.setGravity(Gravity.CENTER);
        overlay.setPadding(dp(28), dp(28), dp(28), dp(28));
        overlay.setBackgroundColor(COLOR_BG);

        TextView badge = text("ON", 24, COLOR_BG, true);
        badge.setGravity(Gravity.CENTER);
        badge.setBackgroundResource(R.drawable.onlifi_badge);
        overlay.addView(badge, new LinearLayout.LayoutParams(dp(76), dp(76)));

        TextView title = text("ONLIFI-Manager", 26, Color.WHITE, true);
        title.setGravity(Gravity.CENTER);
        title.setPadding(0, dp(22), 0, dp(8));
        overlay.addView(title, matchWrap());

        TextView body = text("Unlock with your phone PIN, pattern, password, or biometric.", 15, Color.rgb(220, 235, 228), false);
        body.setGravity(Gravity.CENTER);
        overlay.addView(body, matchWrap());

        Button unlock = primaryButton("Unlock");
        LinearLayout.LayoutParams params = matchWrapMargin(0, dp(28), 0, 0);
        overlay.addView(unlock, params);
        unlock.setOnClickListener(v -> promptForDeviceCredential());

        root.addView(overlay, new FrameLayout.LayoutParams(-1, -1));
    }

    private void promptForDeviceCredential() {
        if (unlockPromptActive) return;

        KeyguardManager keyguardManager = (KeyguardManager) getSystemService(KEYGUARD_SERVICE);
        if (keyguardManager == null || !keyguardManager.isDeviceSecure()) {
            new AlertDialog.Builder(this)
                    .setTitle("Phone lock required")
                    .setMessage("Set a phone PIN, pattern, password, or biometric lock before using saved ONLIFI access.")
                    .setPositiveButton("Open settings", (dialog, which) ->
                            startActivity(new Intent(Settings.ACTION_SECURITY_SETTINGS)))
                    .setNegativeButton("Close app", (dialog, which) -> finish())
                    .setCancelable(false)
                    .show();
            return;
        }

        Intent intent = keyguardManager.createConfirmDeviceCredentialIntent(
                "Unlock ONLIFI-Manager",
                "Use your phone lock to continue."
        );
        if (intent == null) return;
        unlockPromptActive = true;
        startActivityForResult(intent, REQUEST_UNLOCK);
    }

    private void startApp() {
        appStarted = true;
        root.removeAllViews();

        appShell = new LinearLayout(this);
        appShell.setOrientation(LinearLayout.VERTICAL);
        appShell.setBackgroundColor(COLOR_BG);
        root.addView(appShell, new FrameLayout.LayoutParams(-1, -1));

        loadingBar = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        loadingBar.setVisibility(View.GONE);
        appShell.addView(loadingBar, new LinearLayout.LayoutParams(-1, dp(3)));

        content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        LinearLayout.LayoutParams contentParams = new LinearLayout.LayoutParams(-1, 0, 1);
        appShell.addView(content, contentParams);

        bottomNav = new LinearLayout(this);
        bottomNav.setOrientation(LinearLayout.HORIZONTAL);
        bottomNav.setGravity(Gravity.CENTER);
        bottomNav.setPadding(dp(10), dp(8), dp(10), dp(8));
        bottomNav.setBackgroundColor(COLOR_CARD_SOFT);
        appShell.addView(bottomNav, new LinearLayout.LayoutParams(-1, dp(72)));

        renderBottomNav();
        loadSites();
        showHome();
        mainHandler.removeCallbacks(autoRefresh);
        mainHandler.postDelayed(autoRefresh, AUTO_REFRESH_MS);
    }

    private void renderBottomNav() {
        bottomNav.removeAllViews();
        bottomNav.addView(navButton("home", "Home"), new LinearLayout.LayoutParams(0, -1, 1));
        bottomNav.addView(navButton("vouchers", "Vouchers"), new LinearLayout.LayoutParams(0, -1, 1));
        bottomNav.addView(navButton("money", "Mobile Money"), new LinearLayout.LayoutParams(0, -1, 1));
        bottomNav.addView(navButton("account", "Account"), new LinearLayout.LayoutParams(0, -1, 1));
    }

    private TextView navButton(String tab, String label) {
        TextView view = text(label, 13, tab.equals(activeTab) ? COLOR_PRIMARY : COLOR_MUTED, true);
        view.setGravity(Gravity.CENTER);
        view.setBackground(rounded(tab.equals(activeTab) ? Color.argb(38, 16, 185, 129) : Color.TRANSPARENT, 0, Color.TRANSPARENT, dp(8)));
        view.setOnClickListener(v -> {
            activeTab = tab;
            renderBottomNav();
            if ("home".equals(tab)) showHome();
            if ("vouchers".equals(tab)) showVouchers();
            if ("money".equals(tab)) showMobileMoney();
            if ("account".equals(tab)) showAccount();
        });
        return view;
    }

    private void showHome() {
        activeTab = "home";
        renderBottomNav();
        content.removeAllViews();

        ScrollView scroll = new ScrollView(this);
        LinearLayout page = page();
        scroll.addView(page);
        content.addView(scroll, new LinearLayout.LayoutParams(-1, -1));

        addHomeHeader(page);
        addSitePicker(page);
        addQuickActions(page);

        JSONObject cached = readCache("dashboard:" + selectedSiteId);
        if (cached != null) {
            renderDashboard(page, cached, true);
        } else {
            TextView loading = text("Loading live ONLIFI data...", 15, COLOR_MUTED, false);
            loading.setGravity(Gravity.CENTER);
            loading.setPadding(0, dp(32), 0, dp(32));
            page.addView(loading, matchWrap());
        }
        loadDashboard(true);
    }

    private void addHeader(LinearLayout page, String titleValue, String subtitleValue) {
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.CENTER_VERTICAL);
        header.setPadding(0, 0, 0, dp(14));

        LinearLayout copy = new LinearLayout(this);
        copy.setOrientation(LinearLayout.VERTICAL);
        TextView title = text(titleValue, 25, COLOR_INK, true);
        copy.addView(title, matchWrap());
        header.addView(copy, new LinearLayout.LayoutParams(0, -2, 1));

        Button refresh = outlineButton("Refresh");
        refresh.setOnClickListener(v -> {
            if ("home".equals(activeTab)) loadDashboard(false);
            if ("vouchers".equals(activeTab)) showVouchers();
            if ("money".equals(activeTab)) showMobileMoney();
            if ("account".equals(activeTab)) showAccount();
        });
        header.addView(refresh, new LinearLayout.LayoutParams(dp(96), dp(44)));

        page.addView(header, matchWrap());
    }

    private void addHomeHeader(LinearLayout page) {
        LinearLayout card = card();
        card.setOrientation(LinearLayout.HORIZONTAL);
        card.setGravity(Gravity.CENTER_VERTICAL);
        card.setPadding(dp(12), dp(10), dp(12), dp(10));
        card.setBackground(rounded(COLOR_CARD, 1, COLOR_BORDER, dp(10)));

        LinearLayout copy = new LinearLayout(this);
        copy.setOrientation(LinearLayout.VERTICAL);
        copy.addView(text(userName, 18, COLOR_INK, true), matchWrap());
        copy.addView(text("Logged in", 11, COLOR_MUTED, true), matchWrapMargin(0, dp(1), 0, 0));
        card.addView(copy, new LinearLayout.LayoutParams(0, -2, 1));

        String now = new SimpleDateFormat("HH:mm", Locale.getDefault()).format(new Date());
        TextView time = text(now, 20, COLOR_MINT, true);
        time.setGravity(Gravity.CENTER);
        time.setBackground(rounded(Color.argb(32, 16, 185, 129), 1, COLOR_BORDER, dp(8)));
        card.addView(time, new LinearLayout.LayoutParams(dp(82), dp(44)));

        Button refresh = outlineButton("Refresh");
        refresh.setOnClickListener(v -> loadDashboard(false));
        LinearLayout.LayoutParams refreshParams = new LinearLayout.LayoutParams(dp(86), dp(44));
        refreshParams.setMargins(dp(8), 0, 0, 0);
        card.addView(refresh, refreshParams);

        page.addView(card, matchWrapMargin(0, 0, 0, dp(10)));
    }

    private void addUserTimeCard(LinearLayout page) {
        LinearLayout card = card();
        card.setOrientation(LinearLayout.HORIZONTAL);
        card.setGravity(Gravity.CENTER_VERTICAL);
        card.setBackground(rounded(COLOR_CARD, 1, COLOR_BORDER, dp(10)));

        LinearLayout copy = new LinearLayout(this);
        copy.setOrientation(LinearLayout.VERTICAL);
        copy.addView(text("Logged in as", 12, COLOR_MUTED, true), matchWrap());
        copy.addView(text(userName, 19, COLOR_INK, true), matchWrapMargin(0, dp(3), 0, 0));
        card.addView(copy, new LinearLayout.LayoutParams(0, -2, 1));

        String now = new SimpleDateFormat("HH:mm", Locale.getDefault()).format(new Date());
        LinearLayout timeBox = new LinearLayout(this);
        timeBox.setOrientation(LinearLayout.VERTICAL);
        timeBox.setGravity(Gravity.CENTER);
        timeBox.setPadding(dp(14), dp(8), dp(14), dp(8));
        timeBox.setBackground(rounded(Color.argb(32, 16, 185, 129), 1, COLOR_BORDER, dp(8)));
        timeBox.addView(text(now, 20, COLOR_MINT, true));
        timeBox.addView(text("Now", 11, COLOR_MUTED, true));
        card.addView(timeBox, new LinearLayout.LayoutParams(dp(96), -2));

        page.addView(card, matchWrapMargin(0, 0, 0, dp(12)));
    }

    private void addSitePicker(LinearLayout page) {
        LinearLayout siteCard = card();
        siteCard.setPadding(dp(14), dp(12), dp(14), dp(12));
        page.addView(siteCard, matchWrapMargin(0, 0, 0, dp(12)));

        LinearLayout top = new LinearLayout(this);
        top.setOrientation(LinearLayout.HORIZONTAL);
        top.setGravity(Gravity.CENTER_VERTICAL);
        TextView label = text("Active Site", 12, COLOR_MUTED, true);
        top.addView(label, new LinearLayout.LayoutParams(0, -2, 1));
        Button switchButton = outlineButton("Switch");
        top.addView(switchButton, new LinearLayout.LayoutParams(dp(92), dp(40)));
        siteCard.addView(top, matchWrap());

        siteSpinner = new Spinner(this);
        siteSpinner.setBackgroundColor(Color.TRANSPARENT);
        siteCard.addView(siteSpinner, matchWrap());
        switchButton.setOnClickListener(v -> siteSpinner.performClick());
        bindSitesToSpinner();
    }

    private void addQuickActions(LinearLayout page) {
        HorizontalScrollView horizontal = new HorizontalScrollView(this);
        horizontal.setHorizontalScrollBarEnabled(false);
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        horizontal.addView(row);

        Button create = primaryButton("Create Vouchers");
        create.setOnClickListener(v -> showCreateVouchersDialog());
        row.addView(create, new LinearLayout.LayoutParams(dp(168), dp(48)));

        Button withdraw = outlineButton("Withdraw Money");
        LinearLayout.LayoutParams withdrawParams = new LinearLayout.LayoutParams(dp(168), dp(48));
        withdrawParams.setMargins(dp(10), 0, 0, 0);
        withdraw.setOnClickListener(v -> showWithdrawDialog());
        row.addView(withdraw, withdrawParams);

        page.addView(horizontal, matchWrapMargin(0, 0, 0, dp(14)));
    }

    private void renderDashboard(LinearLayout page, JSONObject data, boolean cached) {
        removeDashboardBlocks(page);

        LinearLayout marker = new LinearLayout(this);
        marker.setTag("dashboard_block");
        marker.setOrientation(LinearLayout.VERTICAL);
        page.addView(marker, matchWrap());

        JSONObject stats = data.optJSONObject("stats");
        JSONObject performance = data.optJSONObject("performance");
        JSONObject performanceSummary = performance == null ? null : performance.optJSONObject("summary");
        JSONObject vouchers = data.optJSONObject("vouchers");
        JSONArray clients = data.optJSONArray("clients");
        JSONArray txs = data.optJSONArray("transactions");

        int activeUsers = intValue(data, "active_users",
                stats == null ? 0 : stats.optInt("total_active_users", 0));
        double mobileMoney = performanceSummary == null ? optDouble(stats, "today_revenue") : optDouble(performanceSummary, "mobile_money_total");
        double voucherAmount = performanceSummary == null ? optDouble(vouchers, "total_revenue") : optDouble(performanceSummary, "voucher_total");
        double totalEarnings = performanceSummary == null ? (mobileMoney + voucherAmount) : optDouble(performanceSummary, "combined_total");
        double available = optDouble(data, "available_balance");
        if (available <= 0) available = optDouble(stats, "available_balance");
        if (available <= 0) available = totalEarnings;

        TextView state = text(cached ? "Showing cached data while refreshing" : "Live ONLIFI data", 12, cached ? COLOR_WARN : COLOR_PRIMARY, true);
        state.setPadding(0, 0, 0, dp(8));
        marker.addView(state, matchWrap());

        LinearLayout gridA = new LinearLayout(this);
        gridA.setOrientation(LinearLayout.HORIZONTAL);
        marker.addView(gridA, matchWrapMargin(0, 0, 0, dp(10)));
        gridA.addView(metricCard("Active Users", String.valueOf(activeUsers), "cached router snapshot", COLOR_BLUE), new LinearLayout.LayoutParams(0, dp(128), 1));
        LinearLayout.LayoutParams second = new LinearLayout.LayoutParams(0, dp(128), 1);
        second.setMargins(dp(10), 0, 0, 0);
        gridA.addView(metricCard("Total Earnings", money(totalEarnings), "today", COLOR_PRIMARY), second);

        LinearLayout gridB = new LinearLayout(this);
        gridB.setOrientation(LinearLayout.HORIZONTAL);
        marker.addView(gridB, matchWrapMargin(0, 0, 0, dp(10)));
        gridB.addView(metricCard("Mobile Money", money(mobileMoney), "successful payments", COLOR_BLUE), new LinearLayout.LayoutParams(0, dp(128), 1));
        LinearLayout.LayoutParams gridBSecond = new LinearLayout.LayoutParams(0, dp(128), 1);
        gridBSecond.setMargins(dp(10), 0, 0, 0);
        gridB.addView(metricCard("Vouchers", money(voucherAmount), "physical voucher value", COLOR_PRIMARY), gridBSecond);

        marker.addView(metricCard("Available Balance", money(available), "requires withdrawal endpoint for exact settlement balance", COLOR_WARN), matchWrapMargin(0, 0, 0, dp(14)));

        marker.addView(sectionTitle("Active Users"));
        if (clients == null || clients.length() == 0) {
            marker.addView(emptyText("No active users found for this site."));
        } else {
            marker.addView(activeUserHeader(), matchWrapMargin(0, 0, 0, dp(6)));
            for (int i = 0; i < Math.min(clients.length(), 5); i++) {
                marker.addView(clientRow(clients.optJSONObject(i)), matchWrapMargin(0, 0, 0, dp(8)));
            }
            marker.addView(viewAllButton("View all active clients", this::showClientsListScreen), matchWrapMargin(0, dp(2), 0, dp(8)));
        }

        marker.addView(sectionTitle("Recent Transactions"));
        if (txs == null || txs.length() == 0) {
            marker.addView(emptyText("No recent mobile money transactions."));
        } else {
            marker.addView(transactionHeader(), matchWrapMargin(0, 0, 0, dp(6)));
            for (int i = 0; i < Math.min(txs.length(), 5); i++) {
                marker.addView(transactionRow(txs.optJSONObject(i)), matchWrapMargin(0, 0, 0, dp(8)));
            }
            marker.addView(viewAllButton("View all transactions", this::showTransactionsListScreen), matchWrapMargin(0, dp(2), 0, 0));
        }
    }

    private void removeDashboardBlocks(LinearLayout page) {
        for (int i = page.getChildCount() - 1; i >= 0; i--) {
            View child = page.getChildAt(i);
            Object tag = child.getTag();
            if ("dashboard_block".equals(tag)) {
                page.removeViewAt(i);
            }
        }
    }

    private void showVouchers() {
        activeTab = "vouchers";
        renderBottomNav();
        content.removeAllViews();

        ScrollView scroll = new ScrollView(this);
        LinearLayout page = page();
        scroll.addView(page);
        content.addView(scroll, new LinearLayout.LayoutParams(-1, -1));

        addHeader(page, "Vouchers", "Groups, templates, sales points, types, and manual vouchers");
        addSitePicker(page);

        Button create = primaryButton("Create New Vouchers");
        create.setOnClickListener(v -> showCreateVouchersDialog());
        page.addView(create, matchWrapMargin(0, 0, 0, dp(12)));

        LinearLayout actions = new LinearLayout(this);
        actions.setOrientation(LinearLayout.VERTICAL);
        page.addView(actions, matchWrapMargin(0, 0, 0, dp(12)));
        actions.addView(accountButton("Voucher Templates", "View available printable voucher templates", this::showTemplatesDialog), matchWrapMargin(0, 0, 0, dp(8)));
        actions.addView(accountButton("Sales Points", "View sales point assignments for the active site", this::showSalesPointsDialog), matchWrapMargin(0, 0, 0, dp(8)));
        actions.addView(accountButton("Voucher Types", "View and create package types", this::showVoucherTypesDialog), matchWrapMargin(0, 0, 0, dp(8)));
        actions.addView(accountButton("Manual Voucher", "Create a single custom voucher code", this::showManualVoucherDialog), matchWrapMargin(0, 0, 0, dp(8)));

        LinearLayout groupsHolder = taggedDetail();
        groupsHolder.setTag("voucher_groups");
        groupsHolder.addView(sectionTitle("Voucher Groups"));
        groupsHolder.addView(emptyText("Loading voucher groups..."));
        page.addView(groupsHolder, matchWrap());
        loadVoucherGroups(groupsHolder);
    }

    private void loadVoucherGroups(LinearLayout holder) {
        executor.execute(() -> {
            try {
                JSONObject response = request("GET", "/vouchers/groups", null, true, selectedSiteId);
                JSONArray groups = response.optJSONArray("data");
                mainHandler.post(() -> {
                    holder.removeAllViews();
                    holder.addView(sectionTitle("Voucher Groups"));
                    if (groups == null || groups.length() == 0) {
                        holder.addView(emptyText("No voucher groups found for this site."));
                        return;
                    }
                    for (int i = 0; i < groups.length(); i++) {
                        holder.addView(voucherGroupRow(groups.optJSONObject(i)), matchWrapMargin(0, 0, 0, dp(10)));
                    }
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    holder.removeAllViews();
                    holder.addView(sectionTitle("Voucher Groups"));
                    holder.addView(errorText(e.getMessage()));
                });
            }
        });
    }

    private View voucherGroupRow(JSONObject group) {
        if (group == null) group = new JSONObject();
        LinearLayout row = card();
        row.setBackground(rounded(COLOR_CARD, 1, COLOR_BORDER, dp(10)));

        LinearLayout top = new LinearLayout(this);
        top.setOrientation(LinearLayout.HORIZONTAL);
        top.setGravity(Gravity.CENTER_VERTICAL);

        LinearLayout copy = new LinearLayout(this);
        copy.setOrientation(LinearLayout.VERTICAL);
        copy.addView(text(firstNonEmpty(group.optString("group_name"), "Voucher Group"), 16, COLOR_INK, true), matchWrap());
        String detail = group.optInt("unused_count", 0) + " unused / "
                + group.optInt("total_vouchers", 0) + " total - "
                + money(optDouble(group, "price"));
        copy.addView(text(detail, 12, COLOR_MUTED, false), matchWrapMargin(0, dp(3), 0, 0));
        if (!group.optString("sales_point_name", "").isEmpty()) {
            copy.addView(text("Sales point: " + group.optString("sales_point_name"), 12, COLOR_ORANGE, true), matchWrapMargin(0, dp(3), 0, 0));
        }
        top.addView(copy, new LinearLayout.LayoutParams(0, -2, 1));

        int groupId = group.optInt("id");
        String groupName = firstNonEmpty(group.optString("group_name"), "onlifi-vouchers");
        Button download = outlineButton("PDF");
        download.setOnClickListener(v -> downloadGroupPdf(groupId, groupName));
        top.addView(download, new LinearLayout.LayoutParams(dp(88), dp(44)));
        row.addView(top, matchWrap());
        return row;
    }

    private void showTemplatesDialog() {
        ScrollView scroll = new ScrollView(this);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(10), dp(8), dp(10), dp(8));
        box.setBackground(rounded(COLOR_BG, 1, COLOR_BORDER, dp(10)));
        box.addView(emptyText("Loading templates..."));
        scroll.addView(box);

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("Voucher Templates")
                .setView(scroll)
                .setPositiveButton("Close", null)
                .show();

        loadTemplateRows(box);
    }

    private void loadTemplateRows(LinearLayout box) {
        executor.execute(() -> {
            try {
                JSONObject response = request("GET", "/voucher-templates", null, true, selectedSiteId);
                JSONArray templates = response.optJSONArray("templates");
                mainHandler.post(() -> {
                    box.removeAllViews();
                    if (templates == null || templates.length() == 0) {
                        box.addView(emptyText("No templates found."));
                        return;
                    }
                    for (int i = 0; i < templates.length(); i++) {
                        box.addView(templateRow(templates.optJSONObject(i), box), matchWrapMargin(0, 0, 0, dp(10)));
                    }
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    box.removeAllViews();
                    box.addView(errorText(e.getMessage()));
                });
            }
        });
    }

    private View templateRow(JSONObject template, LinearLayout listBox) {
        if (template == null) template = new JSONObject();
        LinearLayout row = card();
        row.setBackground(rounded(template.optBoolean("is_default") ? Color.argb(38, 16, 185, 129) : COLOR_CARD, 1, COLOR_BORDER, dp(10)));

        row.addView(text(firstNonEmpty(template.optString("name"), "Template"), 16, COLOR_INK, true), matchWrap());
        String detail = layoutLabel(template.optString("layout", "grid-2x4")) + " / "
                + template.optString("paper_size", "A4")
                + (template.optBoolean("show_voucher_type", true) ? " / type shown" : " / type hidden")
                + (template.optBoolean("is_default") ? " / default" : "");
        row.addView(text(detail, 12, COLOR_MUTED, false), matchWrapMargin(0, dp(3), 0, dp(10)));

        LinearLayout actions = new LinearLayout(this);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        Button edit = outlineButton("Edit");
        Button def = primaryButton("Default");
        final int templateId = template.optInt("id");
        final JSONObject selectedTemplate = template;
        edit.setOnClickListener(v -> showEditTemplateDialog(selectedTemplate, listBox));
        def.setOnClickListener(v -> setDefaultTemplate(templateId, listBox));
        actions.addView(edit, new LinearLayout.LayoutParams(0, dp(42), 1));
        LinearLayout.LayoutParams defParams = new LinearLayout.LayoutParams(0, dp(42), 1);
        defParams.setMargins(dp(8), 0, 0, 0);
        actions.addView(def, defParams);
        row.addView(actions, matchWrap());
        return row;
    }

    private void showEditTemplateDialog(JSONObject template, LinearLayout refreshBox) {
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(10), dp(4), dp(10), 0);
        form.setBackground(rounded(COLOR_CARD_SOFT, 1, COLOR_BORDER, dp(10)));

        String[] layouts = {"single", "grid-2x2", "grid-2x4", "grid-3x3", "grid-4x5", "grid-5x8", "grid-8x10"};
        String[] layoutLabels = {"1 voucher / PDF", "4 vouchers / PDF", "8 vouchers / PDF", "9 vouchers / PDF", "20 vouchers / PDF", "40 vouchers / PDF", "80 vouchers / PDF"};
        Spinner layoutSpinner = spinner(layoutLabels);
        layoutSpinner.setSelection(indexOf(layouts, template.optString("layout", "grid-2x4"), 2));
        Spinner showTypeSpinner = spinner(new String[]{"Show voucher type", "Hide voucher type"});
        showTypeSpinner.setSelection(template.optBoolean("show_voucher_type", true) ? 0 : 1);
        Spinner defaultSpinner = spinner(new String[]{"Keep default setting", "Set as default template"});

        form.addView(label("Vouchers per PDF"));
        form.addView(layoutSpinner, matchWrapMargin(0, 0, 0, dp(12)));
        form.addView(label("Voucher Type Elements"));
        form.addView(showTypeSpinner, matchWrapMargin(0, 0, 0, dp(12)));
        form.addView(label("Default Template"));
        form.addView(defaultSpinner, matchWrap());

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle(firstNonEmpty(template.optString("name"), "Edit Template"))
                .setView(form)
                .setNegativeButton("Cancel", null)
                .setPositiveButton("Save", null)
                .create();
        dialog.setOnShowListener(d -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v -> {
            int selectedLayout = layoutSpinner.getSelectedItemPosition();
            boolean setDefault = defaultSpinner.getSelectedItemPosition() == 1;
            executor.execute(() -> {
                try {
                    JSONObject payload = new JSONObject()
                            .put("layout", layouts[Math.max(0, selectedLayout)])
                            .put("show_voucher_type", showTypeSpinner.getSelectedItemPosition() == 0)
                            .put("is_default", setDefault || template.optBoolean("is_default"));
                    request("PUT", "/voucher-templates/" + template.optInt("id"), payload, true, selectedSiteId);
                    mainHandler.post(() -> {
                        dialog.dismiss();
                        toast("Template updated.");
                        loadTemplateRows(refreshBox);
                    });
                } catch (Exception e) {
                    mainHandler.post(() -> alert("Template update failed", e.getMessage()));
                }
            });
        }));
        dialog.show();
    }

    private void setDefaultTemplate(int templateId, LinearLayout refreshBox) {
        if (templateId <= 0) return;
        executor.execute(() -> {
            try {
                request("POST", "/voucher-templates/" + templateId + "/set-default", new JSONObject(), true, selectedSiteId);
                mainHandler.post(() -> {
                    toast("Default template updated.");
                    loadTemplateRows(refreshBox);
                });
            } catch (Exception e) {
                mainHandler.post(() -> alert("Default update failed", e.getMessage()));
            }
        });
    }

    private int indexOf(String[] values, String value, int fallback) {
        for (int i = 0; i < values.length; i++) {
            if (values[i].equals(value)) return i;
        }
        return fallback;
    }

    private String layoutLabel(String layout) {
        switch (layout) {
            case "single": return "1 voucher / PDF";
            case "grid-2x2": return "4 vouchers / PDF";
            case "grid-3x3": return "9 vouchers / PDF";
            case "grid-4x5": return "20 vouchers / PDF";
            case "grid-5x8": return "40 vouchers / PDF";
            case "grid-8x10": return "80 vouchers / PDF";
            case "grid-2x4":
            default: return "8 vouchers / PDF";
        }
    }

    private void showSalesPointsDialog() {
        showListDialog("Sales Points", "/sales-points", "name", "location");
    }

    private void showListDialog(String title, String endpoint, String primaryKey, String secondaryKey) {
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(8), 0, dp(8), 0);
        box.addView(emptyText("Loading..."));

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle(title)
                .setView(box)
                .setPositiveButton("Close", null)
                .show();

        executor.execute(() -> {
            try {
                JSONObject response = request("GET", endpoint, null, true, selectedSiteId);
                JSONArray rows = response.optJSONArray("data");
                if (rows == null) rows = response.optJSONArray("templates");
                final JSONArray finalRows = rows;
                mainHandler.post(() -> {
                    box.removeAllViews();
                    if (finalRows == null || finalRows.length() == 0) {
                        box.addView(emptyText("No records found."));
                        return;
                    }
                    for (int i = 0; i < finalRows.length(); i++) {
                        JSONObject row = finalRows.optJSONObject(i);
                        box.addView(simpleRow(firstNonEmpty(row.optString(primaryKey), row.optString("name"), "Item"), firstNonEmpty(row.optString(secondaryKey), row.optString("description"), "")), matchWrapMargin(0, 0, 0, dp(8)));
                    }
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    box.removeAllViews();
                    box.addView(errorText(e.getMessage()));
                });
            }
        });
    }

    private void showVoucherTypesDialog() {
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(8), 0, dp(8), 0);
        form.addView(emptyText("Loading voucher types..."));

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("Voucher Types")
                .setView(form)
                .setNegativeButton("Close", null)
                .setPositiveButton("New Type", null)
                .create();
        dialog.setOnShowListener(d -> {
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v -> showCreateVoucherTypeDialog());
        });
        dialog.show();

        executor.execute(() -> {
            try {
                JSONObject response = request("GET", "/vouchers/types", null, true, selectedSiteId);
                JSONArray rows = response.optJSONArray("types");
                mainHandler.post(() -> {
                    form.removeAllViews();
                    if (rows == null || rows.length() == 0) {
                        form.addView(emptyText("No voucher types found."));
                    } else {
                        for (int i = 0; i < rows.length(); i++) {
                            JSONObject row = rows.optJSONObject(i);
                            form.addView(simpleRow(row.optString("type_name", "Voucher Type"),
                                    row.optInt("duration_hours", 0) + " hours - " + money(optDouble(row, "base_amount"))), matchWrapMargin(0, 0, 0, dp(8)));
                        }
                    }
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    form.removeAllViews();
                    form.addView(errorText(e.getMessage()));
                });
            }
        });
    }

    private void showCreateVoucherTypeDialog() {
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(8), 0, dp(8), 0);
        EditText name = input("Type name e.g. 2 Hours", false);
        EditText hours = input("Duration hours", false);
        hours.setInputType(InputType.TYPE_CLASS_NUMBER);
        EditText price = input("Base amount UGX", false);
        price.setInputType(InputType.TYPE_CLASS_NUMBER);
        form.addView(label("Name"));
        form.addView(name, matchWrapMargin(0, 0, 0, dp(10)));
        form.addView(label("Duration"));
        form.addView(hours, matchWrapMargin(0, 0, 0, dp(10)));
        form.addView(label("Amount"));
        form.addView(price, matchWrap());

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("New Voucher Type")
                .setView(form)
                .setNegativeButton("Cancel", null)
                .setPositiveButton("Create", null)
                .create();
        dialog.setOnShowListener(d -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v -> {
            executor.execute(() -> {
                try {
                    JSONObject payload = new JSONObject()
                            .put("type_name", name.getText().toString().trim())
                            .put("duration_hours", parseInt(hours.getText().toString(), 1))
                            .put("base_amount", parseInt(price.getText().toString(), 0));
                    request("POST", "/vouchers/types", payload, true, selectedSiteId);
                    mainHandler.post(() -> {
                        dialog.dismiss();
                        toast("Voucher type created.");
                    });
                } catch (Exception e) {
                    mainHandler.post(() -> alert("Could not create type", e.getMessage()));
                }
            });
        }));
        dialog.show();
    }

    private void showManualVoucherDialog() {
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(8), 0, dp(8), 0);
        Spinner typeSpinner = spinner(new String[]{"Loading types..."});
        EditText code = input("Voucher code", false);
        form.addView(label("Voucher Type"));
        form.addView(typeSpinner, matchWrapMargin(0, 0, 0, dp(10)));
        form.addView(label("Code"));
        form.addView(code, matchWrap());

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("Manual Voucher")
                .setView(form)
                .setNegativeButton("Cancel", null)
                .setPositiveButton("Create", null)
                .create();
        dialog.setOnShowListener(d -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v -> {
            int selected = typeSpinner.getSelectedItemPosition();
            if (selected < 0 || selected >= voucherTypes.size()) {
                toast("Choose a voucher type.");
                return;
            }
            executor.execute(() -> {
                try {
                    JSONObject payload = new JSONObject()
                            .put("voucher_code", code.getText().toString().trim())
                            .put("voucher_type_id", voucherTypes.get(selected).id);
                    request("POST", "/vouchers/manual", payload, true, selectedSiteId);
                    mainHandler.post(() -> {
                        dialog.dismiss();
                        toast("Manual voucher created.");
                        if ("vouchers".equals(activeTab)) showVouchers();
                    });
                } catch (Exception e) {
                    mainHandler.post(() -> alert("Manual voucher failed", e.getMessage()));
                }
            });
        }));
        dialog.show();
        loadVoucherTypes(typeSpinner);
    }

    private void showMobileMoney() {
        activeTab = "money";
        renderBottomNav();
        content.removeAllViews();

        ScrollView scroll = new ScrollView(this);
        LinearLayout page = page();
        scroll.addView(page);
        content.addView(scroll, new LinearLayout.LayoutParams(-1, -1));

        addHeader(page, "Mobile Money", "Withdrawable balance, earnings, and performance");
        addSitePicker(page);

        LinearLayout summary = taggedDetail();
        summary.setTag("money_summary");
        summary.addView(emptyText("Loading mobile money summary..."));
        page.addView(summary, matchWrapMargin(0, 0, 0, dp(12)));

        Button withdraw = primaryButton("Withdraw Money");
        withdraw.setBackground(rounded(COLOR_WARN, 0, COLOR_WARN, dp(8)));
        withdraw.setOnClickListener(v -> showWithdrawDialog());
        page.addView(withdraw, matchWrapMargin(0, 0, 0, dp(14)));

        TextView title = sectionTitle("Analyze Performance");
        page.addView(title);

        LinearLayout periods = new LinearLayout(this);
        periods.setOrientation(LinearLayout.HORIZONTAL);
        HorizontalScrollView periodScroll = new HorizontalScrollView(this);
        periodScroll.setHorizontalScrollBarEnabled(false);
        periodScroll.addView(periods);
        page.addView(periodScroll, matchWrapMargin(0, 0, 0, dp(12)));

        String[][] options = {
                {"today", "Today"},
                {"yesterday", "Yesterday"},
                {"week", "This Week"},
                {"month", "This Month"},
                {"three_months", "3 Months"},
                {"six_months", "6 Months"}
        };
        LinearLayout performance = taggedDetail();
        performance.setTag("money_performance");
        page.addView(performance, matchWrap());

        for (String[] option : options) {
            Button button = outlineButton(option[1]);
            button.setOnClickListener(v -> loadMobileMoneyPerformance(performance, option[0]));
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(dp(126), dp(44));
            params.setMargins(0, 0, dp(8), 0);
            periods.addView(button, params);
        }

        loadMobileMoneySummary(summary);
        loadMobileMoneyPerformance(performance, "today");
    }

    private void loadMobileMoneySummary(LinearLayout holder) {
        executor.execute(() -> {
            JSONObject wallet = null;
            try {
                wallet = request("GET", "/tenant/mobile-wallet", null, true, selectedSiteId);
            } catch (Exception ignored) {
            }

            try {
                JSONObject stats = request("GET", "/dashboard/stats", null, true, selectedSiteId);
                JSONObject performance = request("GET", "/transactions/performance?period=today", null, true, selectedSiteId);
                JSONObject summary = performance.optJSONObject("summary");
                JSONObject finalWallet = wallet;
                mainHandler.post(() -> {
                    holder.removeAllViews();
                    double withdrawable = finalWallet == null
                            ? optDouble(summary, "mobile_money_total")
                            : optDouble(finalWallet, "available_balance");
                    double today = optDouble(summary, "mobile_money_total");
                    double pending = finalWallet == null ? 0 : optDouble(finalWallet, "pending_withdrawals");
                    holder.addView(metricCard("Withdrawable Balance", money(withdrawable), finalWallet == null ? "estimated until wallet endpoint is enabled" : "available now", COLOR_ORANGE), matchWrapMargin(0, 0, 0, dp(10)));
                    holder.addView(metricCard("Today Earnings", money(today), stats.optInt("today_transactions", 0) + " successful payments", COLOR_PRIMARY), matchWrapMargin(0, 0, 0, dp(10)));
                    holder.addView(metricCard("Pending Withdrawals", money(pending), "settlement queue", COLOR_BLUE), matchWrap());
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    holder.removeAllViews();
                    holder.addView(errorText(e.getMessage()));
                });
            }
        });
    }

    private void loadMobileMoneyPerformance(LinearLayout holder, String period) {
        holder.removeAllViews();
        holder.addView(emptyText("Loading " + period.replace('_', ' ') + " performance..."));
        executor.execute(() -> {
            try {
                JSONObject analytics = request("GET", "/transactions/performance?period=" + period, null, true, selectedSiteId);
                JSONObject summary = analytics.optJSONObject("summary");
                JSONArray rows = analytics.optJSONArray("breakdown");
                mainHandler.post(() -> {
                    holder.removeAllViews();
                    holder.addView(metricCard("Mobile Money Total", money(optDouble(summary, "mobile_money_total")), period.replace('_', ' '), COLOR_PRIMARY), matchWrapMargin(0, 0, 0, dp(10)));
                    holder.addView(metricCard("Transactions", String.valueOf(summary == null ? 0 : summary.optInt("mobile_money_transactions", 0)), "successful payments", COLOR_BLUE), matchWrapMargin(0, 0, 0, dp(12)));
                    if (rows != null) {
                        holder.addView(sectionTitle("Earnings Graph"));
                        addSingleMetricGraph(holder, rows, "mobile_money_total", COLOR_PRIMARY);
                    }
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    holder.removeAllViews();
                    holder.addView(errorText(e.getMessage()));
                });
            }
        });
    }

    private void showAccount() {
        activeTab = "account";
        renderBottomNav();
        content.removeAllViews();

        ScrollView scroll = new ScrollView(this);
        LinearLayout page = page();
        scroll.addView(page);
        content.addView(scroll, new LinearLayout.LayoutParams(-1, -1));

        addHeader(page, "Account", "");
        addSitePicker(page);

        page.addView(accountButton("Clients List", "View active clients for the selected site", () -> loadClientsAccountView(page)), matchWrapMargin(0, 0, 0, dp(10)));
        page.addView(accountButton("Withdraw Money", "Submit settlement request when Laravel endpoint is enabled", this::showWithdrawDialog), matchWrapMargin(0, 0, 0, dp(10)));
        page.addView(accountButton("Mobile Money Transactions", "Successful customer payments by site", () -> loadTransactionsAccountView(page)), matchWrapMargin(0, 0, 0, dp(10)));
        page.addView(accountButton("Analyze Performance", "Today, week, and month performance metrics", () -> loadPerformanceAccountView(page, "today")), matchWrapMargin(0, 0, 0, dp(14)));

        loadClientsAccountView(page);
    }

    private View accountButton(String title, String body, Runnable action) {
        LinearLayout card = card();
        card.setOrientation(LinearLayout.HORIZONTAL);
        card.setGravity(Gravity.CENTER_VERTICAL);

        LinearLayout copy = new LinearLayout(this);
        copy.setOrientation(LinearLayout.VERTICAL);
        copy.addView(text(title, 16, COLOR_INK, true), matchWrap());
        copy.addView(text(body, 12, COLOR_MUTED, false), matchWrap());
        card.addView(copy, new LinearLayout.LayoutParams(0, -2, 1));

        TextView arrow = text("Open", 13, COLOR_PRIMARY, true);
        card.addView(arrow);
        card.setOnClickListener(v -> action.run());
        return card;
    }

    private View viewAllButton(String title, Runnable action) {
        TextView button = text(title, 14, COLOR_PRIMARY, true);
        button.setGravity(Gravity.CENTER);
        button.setPadding(dp(12), dp(12), dp(12), dp(12));
        button.setBackground(rounded(Color.argb(30, 16, 185, 129), 1, COLOR_BORDER, dp(8)));
        button.setOnClickListener(v -> action.run());
        return button;
    }

    private void clearAccountDetails(LinearLayout page) {
        for (int i = page.getChildCount() - 1; i >= 0; i--) {
            View child = page.getChildAt(i);
            if ("account_detail".equals(child.getTag())) page.removeViewAt(i);
        }
    }

    private void loadVoucherAccountView(LinearLayout page) {
        clearAccountDetails(page);
        LinearLayout detail = taggedDetail();
        detail.addView(sectionTitle("Vouchers"));
        detail.addView(emptyText("Loading vouchers..."));
        page.addView(detail, matchWrap());

        executor.execute(() -> {
            try {
                JSONObject stats = request("GET", "/vouchers/statistics", null, true, selectedSiteId);
                JSONObject list = request("GET", "/vouchers?per_page=12", null, true, selectedSiteId);
                JSONArray rows = list.optJSONArray("data");
                mainHandler.post(() -> {
                    detail.removeAllViews();
                    detail.addView(sectionTitle("Vouchers"));
                    detail.addView(metricCard("Unused Vouchers", String.valueOf(stats.optInt("unused_vouchers", 0)), "ready for sale", COLOR_PRIMARY), matchWrapMargin(0, 0, 0, dp(10)));
                    detail.addView(metricCard("Voucher Revenue", money(optDouble(stats, "total_revenue")), "used vouchers", COLOR_BLUE), matchWrapMargin(0, 0, 0, dp(10)));
                    if (rows == null || rows.length() == 0) {
                        detail.addView(emptyText("No vouchers found."));
                    } else {
                        for (int i = 0; i < Math.min(rows.length(), 12); i++) {
                            JSONObject row = rows.optJSONObject(i);
                            detail.addView(simpleRow(
                                    row.optString("voucher_code", "Voucher"),
                                    row.optString("status", "") + " - " + money(optDouble(row, "price"))
                            ), matchWrapMargin(0, 0, 0, dp(8)));
                        }
                    }
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    detail.removeAllViews();
                    detail.addView(sectionTitle("Vouchers"));
                    detail.addView(errorText(e.getMessage()));
                });
            }
        });
    }

    private void loadClientsAccountView(LinearLayout page) {
        clearAccountDetails(page);
        LinearLayout detail = taggedDetail();
        detail.addView(sectionTitle("Clients List"));
        detail.addView(emptyText("Loading active clients..."));
        page.addView(detail, matchWrap());
        loadClientsInto(detail, 300);
    }

    private void showClientsListScreen() {
        activeTab = "account";
        renderBottomNav();
        content.removeAllViews();
        ScrollView scroll = new ScrollView(this);
        LinearLayout page = page();
        scroll.addView(page);
        content.addView(scroll, new LinearLayout.LayoutParams(-1, -1));
        addHeader(page, "Clients List", "");
        addSitePicker(page);
        LinearLayout list = taggedDetail();
        list.addView(emptyText("Loading active clients..."));
        page.addView(list, matchWrap());
        loadClientsInto(list, 500);
    }

    private void loadClientsInto(LinearLayout holder, int limit) {
        executor.execute(() -> {
            try {
                JSONObject clientsData = request("GET", "/clients?limit=" + limit, null, true, selectedSiteId);
                JSONArray rows = clientsData.optJSONArray("clients");
                if (rows == null) rows = clientsData.optJSONArray("data");
                final JSONArray finalRows = rows;
                mainHandler.post(() -> {
                    holder.removeAllViews();
                    holder.addView(sectionTitle("Clients List"));
                    if (finalRows == null || finalRows.length() == 0) {
                        holder.addView(emptyText("No active clients found."));
                        return;
                    }
                    holder.addView(activeUserHeader(), matchWrapMargin(0, 0, 0, dp(6)));
                    for (int i = 0; i < finalRows.length(); i++) {
                        holder.addView(clientRow(finalRows.optJSONObject(i)), matchWrapMargin(0, 0, 0, dp(8)));
                    }
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    holder.removeAllViews();
                    holder.addView(sectionTitle("Clients List"));
                    holder.addView(errorText(e.getMessage()));
                });
            }
        });
    }

    private void showTransactionsListScreen() {
        activeTab = "money";
        renderBottomNav();
        content.removeAllViews();
        ScrollView scroll = new ScrollView(this);
        LinearLayout page = page();
        scroll.addView(page);
        content.addView(scroll, new LinearLayout.LayoutParams(-1, -1));
        addHeader(page, "Recent Transactions", "");
        addSitePicker(page);
        LinearLayout list = taggedDetail();
        list.addView(emptyText("Loading transactions..."));
        page.addView(list, matchWrap());
        loadTransactionsInto(list, 100);
    }

    private void loadTransactionsInto(LinearLayout holder, int perPage) {
        executor.execute(() -> {
            try {
                JSONObject txs = request("GET", "/transactions?status=success&per_page=" + perPage, null, true, selectedSiteId);
                JSONArray rows = txs.optJSONArray("data");
                final JSONArray finalRows = rows;
                mainHandler.post(() -> {
                    holder.removeAllViews();
                    holder.addView(sectionTitle("Recent Transactions"));
                    if (finalRows == null || finalRows.length() == 0) {
                        holder.addView(emptyText("No successful mobile money transactions."));
                        return;
                    }
                    holder.addView(transactionHeader(), matchWrapMargin(0, 0, 0, dp(6)));
                    for (int i = 0; i < finalRows.length(); i++) {
                        holder.addView(transactionRow(finalRows.optJSONObject(i)), matchWrapMargin(0, 0, 0, dp(8)));
                    }
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    holder.removeAllViews();
                    holder.addView(sectionTitle("Recent Transactions"));
                    holder.addView(errorText(e.getMessage()));
                });
            }
        });
    }

    private void loadTransactionsAccountView(LinearLayout page) {
        clearAccountDetails(page);
        LinearLayout detail = taggedDetail();
        detail.addView(sectionTitle("Mobile Money Transactions"));
        detail.addView(emptyText("Loading transactions..."));
        page.addView(detail, matchWrap());
        loadTransactionsInto(detail, 50);
    }

    private void loadPerformanceAccountView(LinearLayout page, String period) {
        clearAccountDetails(page);
        LinearLayout detail = taggedDetail();
        detail.addView(sectionTitle("Analyze Performance"));
        detail.addView(emptyText("Loading performance..."));
        page.addView(detail, matchWrap());

        executor.execute(() -> {
            try {
                JSONObject analytics = request("GET", "/transactions/performance?period=" + period, null, true, selectedSiteId);
                JSONObject summary = analytics.optJSONObject("summary");
                JSONArray breakdown = analytics.optJSONArray("breakdown");
                mainHandler.post(() -> {
                    detail.removeAllViews();
                    detail.addView(sectionTitle("Analyze Performance"));
                    detail.addView(metricCard("Combined Total", money(optDouble(summary, "combined_total")), period, COLOR_PRIMARY), matchWrapMargin(0, 0, 0, dp(10)));
                    detail.addView(metricCard("Mobile Money", money(optDouble(summary, "mobile_money_total")), summary == null ? "0 transactions" : summary.optInt("mobile_money_transactions") + " transactions", COLOR_BLUE), matchWrapMargin(0, 0, 0, dp(10)));
                    detail.addView(metricCard("Vouchers", money(optDouble(summary, "voucher_total")), summary == null ? "0 sold" : summary.optInt("vouchers_sold") + " sold", COLOR_WARN), matchWrapMargin(0, 0, 0, dp(10)));
                    if (breakdown != null) {
                        detail.addView(sectionTitle("Performance Graph"));
                        addCombinedGraph(detail, breakdown);
                    }
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    detail.removeAllViews();
                    detail.addView(sectionTitle("Analyze Performance"));
                    detail.addView(errorText(e.getMessage()));
                });
            }
        });
    }

    private void loadSites() {
        executor.execute(() -> {
            try {
                JSONObject response = request("GET", "/sites", null, true, 0);
                JSONArray rows = response.optJSONArray("sites");
                List<Site> nextSites = new ArrayList<>();
                if (rows != null) {
                    for (int i = 0; i < rows.length(); i++) {
                        JSONObject row = rows.optJSONObject(i);
                        nextSites.add(new Site(row.optInt("id"), row.optString("name", "Site " + row.optInt("id"))));
                    }
                }
                mainHandler.post(() -> {
                    sites.clear();
                    sites.addAll(nextSites);
                    if (selectedSiteId == 0 && !sites.isEmpty()) {
                        selectedSiteId = sites.get(0).id;
                        preferences.edit().putInt(PREF_SELECTED_SITE, selectedSiteId).apply();
                    }
                    bindSitesToSpinner();
                    if ("home".equals(activeTab)) loadDashboard(true);
                });
            } catch (Exception e) {
                mainHandler.post(() -> toast("Could not load sites: " + e.getMessage()));
            }
        });
    }

    private void bindSitesToSpinner() {
        if (siteSpinner == null) return;
        List<String> names = new ArrayList<>();
        int selectedIndex = 0;
        for (int i = 0; i < sites.size(); i++) {
            Site site = sites.get(i);
            names.add(site.name);
            if (site.id == selectedSiteId) selectedIndex = i;
        }
        if (names.isEmpty()) names.add("No site loaded");

        ArrayAdapter<String> adapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, names);
        adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        siteSpinner.setAdapter(adapter);
        siteSpinner.setSelection(selectedIndex, false);
        siteSpinner.setOnItemSelectedListener(new AdapterView.OnItemSelectedListener() {
            @Override
            public void onItemSelected(AdapterView<?> parent, View view, int position, long id) {
                if (position < sites.size()) {
                    int nextSite = sites.get(position).id;
                    if (nextSite != selectedSiteId) {
                        selectedSiteId = nextSite;
                        preferences.edit().putInt(PREF_SELECTED_SITE, selectedSiteId).apply();
                        if ("home".equals(activeTab)) showHome();
                        if ("account".equals(activeTab)) showAccount();
                    }
                }
            }

            @Override
            public void onNothingSelected(AdapterView<?> parent) {
            }
        });
    }

    private void loadDashboard(boolean preferCache) {
        if (selectedSiteId == 0 || token == null) return;
        if (!isNetworkAvailable()) {
            toast("No internet connection.");
            return;
        }
        setLoading(true);
        executor.execute(() -> {
            try {
                JSONObject payload = new JSONObject();
                JSONObject stats = request("GET", "/dashboard/stats", null, true, selectedSiteId);
                JSONObject performance = request("GET", "/transactions/performance?period=today", null, true, selectedSiteId);
                JSONObject vouchers = request("GET", "/vouchers/statistics", null, true, selectedSiteId);
                JSONObject clientsData = request("GET", "/clients?limit=100", null, true, selectedSiteId);
                JSONObject txData = request("GET", "/transactions?status=success&per_page=10", null, true, selectedSiteId);

                JSONArray clients = clientsData.optJSONArray("clients");
                if (clients == null) clients = clientsData.optJSONArray("data");
                JSONArray txs = txData.optJSONArray("transactions");
                if (txs == null) txs = txData.optJSONArray("data");

                int totalClients = clientsData.has("total") ? clientsData.optInt("total") : (clients == null ? 0 : clients.length());
                payload.put("stats", stats);
                payload.put("performance", performance);
                payload.put("vouchers", vouchers);
                payload.put("clients", clients == null ? new JSONArray() : clients);
                payload.put("transactions", txs == null ? new JSONArray() : txs);
                payload.put("active_users", totalClients > 0 ? totalClients : stats.optInt("total_active_users", 0));

                writeCache("dashboard:" + selectedSiteId, payload);
                mainHandler.post(() -> {
                    setLoading(false);
                    if ("home".equals(activeTab)) showHomeWithData(payload);
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    setLoading(false);
                    if (!preferCache) alert("Refresh failed", e.getMessage());
                });
            }
        });
    }

    private void showHomeWithData(JSONObject payload) {
        content.removeAllViews();
        ScrollView scroll = new ScrollView(this);
        LinearLayout page = page();
        scroll.addView(page);
        content.addView(scroll, new LinearLayout.LayoutParams(-1, -1));
        addHomeHeader(page);
        addSitePicker(page);
        addQuickActions(page);
        renderDashboard(page, payload, false);
    }

    private void showCreateVouchersDialog() {
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(12), dp(4), dp(12), 0);
        form.setBackground(rounded(COLOR_CARD_SOFT, 1, COLOR_BORDER, dp(10)));

        Spinner typeSpinner = spinner(new String[]{"Loading voucher types..."});
        Spinner salesPointSpinner = spinner(new String[]{"Any sales point"});
        Spinner styleSpinner = spinner(new String[]{"numbers", "mixed", "letters"});
        EditText count = input("Count", false);
        count.setInputType(InputType.TYPE_CLASS_NUMBER);
        count.setText("10");
        EditText codeLength = input("Code length 6-12", false);
        codeLength.setInputType(InputType.TYPE_CLASS_NUMBER);
        codeLength.setText("6");

        form.addView(label("Voucher Type"));
        form.addView(typeSpinner, matchWrapMargin(0, 0, 0, dp(12)));
        form.addView(label("Sales Point (optional)"));
        form.addView(salesPointSpinner, matchWrapMargin(0, 0, 0, dp(12)));
        form.addView(label("Voucher Style"));
        form.addView(styleSpinner, matchWrapMargin(0, 0, 0, dp(12)));
        form.addView(label("Number of vouchers"));
        form.addView(count, matchWrapMargin(0, 0, 0, dp(12)));
        form.addView(label("Code length"));
        form.addView(codeLength, matchWrap());

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("Create Vouchers")
                .setView(form)
                .setNegativeButton("Cancel", null)
                .setPositiveButton("Create", null)
                .create();
        dialog.setOnShowListener(d -> {
            Button button = dialog.getButton(AlertDialog.BUTTON_POSITIVE);
            button.setOnClickListener(v -> {
                int selected = typeSpinner.getSelectedItemPosition();
                if (selected < 0 || selected >= voucherTypes.size()) {
                    toast("Choose a voucher type.");
                    return;
                }
                VoucherType type = voucherTypes.get(selected);
                int salesPointPosition = salesPointSpinner.getSelectedItemPosition();
                SalesPoint salesPoint = salesPointPosition > 0 && salesPointPosition - 1 < salesPoints.size()
                        ? salesPoints.get(salesPointPosition - 1)
                        : null;
                int voucherCount = parseInt(count.getText().toString(), 10);
                int length = parseInt(codeLength.getText().toString(), 6);
                String style = String.valueOf(styleSpinner.getSelectedItem());
                if (voucherCount < 1 || voucherCount > 1000) {
                    toast("Count must be between 1 and 1000.");
                    return;
                }
                if (length < 6 || length > 12) {
                    toast("Code length must be 6-12.");
                    return;
                }
                button.setEnabled(false);
                button.setText("Creating...");
                executor.execute(() -> {
                    try {
                        JSONObject payload = new JSONObject()
                                .put("group_name", "Mobile " + type.name + " " + new SimpleDateFormat("MMdd-HHmm", Locale.US).format(new Date()))
                                .put("profile_name", type.name)
                                .put("validity_hours", type.hours)
                                .put("validity_minutes", type.minutes)
                                .put("price", type.price)
                                .put("count", voucherCount)
                                .put("code_format", style)
                                .put("code_length", length);
                        if (salesPoint != null) payload.put("sales_point_id", salesPoint.id);
                        JSONObject response = request("POST", "/vouchers/generate-batch", payload, true, selectedSiteId);
                        JSONObject group = response.optJSONObject("group");
                        int groupId = group == null ? response.optInt("group_id", 0) : group.optInt("id", 0);
                        String groupName = group == null ? "onlifi-vouchers" : group.optString("group_name", "onlifi-vouchers");
                        mainHandler.post(() -> {
                            dialog.dismiss();
                            if (groupId > 0) {
                                downloadGroupPdf(groupId, groupName);
                                toast("Vouchers created. PDF download started.");
                            } else {
                                toast("Vouchers created.");
                            }
                            loadDashboard(false);
                            if ("vouchers".equals(activeTab)) showVouchers();
                        });
                    } catch (Exception e) {
                        mainHandler.post(() -> {
                            button.setEnabled(true);
                            button.setText("Create");
                            alert("Voucher creation failed", e.getMessage());
                        });
                    }
                });
            });
        });
        dialog.show();

        loadVoucherTypes(typeSpinner);
        loadSalesPoints(salesPointSpinner);
    }

    private void loadVoucherTypes(Spinner spinner) {
        executor.execute(() -> {
            try {
                JSONObject response = request("GET", "/vouchers/types", null, true, selectedSiteId);
                JSONArray rows = response.optJSONArray("types");
                List<VoucherType> next = new ArrayList<>();
                List<String> labels = new ArrayList<>();
                if (rows != null) {
                    for (int i = 0; i < rows.length(); i++) {
                        JSONObject row = rows.optJSONObject(i);
                        VoucherType type = new VoucherType(
                                row.optInt("id"),
                                row.optString("type_name", "Voucher"),
                                row.optInt("duration_hours", 1),
                                row.optInt("validity_minutes", Math.max(1, row.optInt("duration_hours", 1)) * 60),
                                optDouble(row, "base_amount")
                        );
                        next.add(type);
                        labels.add(type.name + " - " + money(type.price));
                    }
                }
                if (labels.isEmpty()) labels.add("No voucher types found");
                mainHandler.post(() -> {
                    voucherTypes.clear();
                    voucherTypes.addAll(next);
                    ArrayAdapter<String> adapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, labels);
                    adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
                    spinner.setAdapter(adapter);
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    ArrayAdapter<String> adapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, new String[]{"Could not load types"});
                    spinner.setAdapter(adapter);
                });
            }
        });
    }

    private void loadSalesPoints(Spinner spinner) {
        executor.execute(() -> {
            try {
                JSONObject response = request("GET", "/sales-points", null, true, selectedSiteId);
                JSONArray rows = response.optJSONArray("data");
                List<SalesPoint> next = new ArrayList<>();
                List<String> labels = new ArrayList<>();
                labels.add("Any sales point");
                if (rows != null) {
                    for (int i = 0; i < rows.length(); i++) {
                        JSONObject row = rows.optJSONObject(i);
                        SalesPoint point = new SalesPoint(row.optInt("id"), row.optString("name", "Sales Point"));
                        next.add(point);
                        labels.add(point.name);
                    }
                }
                mainHandler.post(() -> {
                    salesPoints.clear();
                    salesPoints.addAll(next);
                    ArrayAdapter<String> adapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, labels);
                    adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
                    spinner.setAdapter(adapter);
                });
            } catch (Exception e) {
                mainHandler.post(() -> {
                    ArrayAdapter<String> adapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, new String[]{"Any sales point"});
                    spinner.setAdapter(adapter);
                });
            }
        });
    }

    private void downloadGroupPdf(int groupId, String groupName) {
        if (groupId <= 0) {
            toast("Voucher group is missing.");
            return;
        }
        requestDownloadPermissionsIfNeeded();
        try {
            String safeName = sanitizeFileName(groupName);
            Uri uri = Uri.parse(API_BASE + "/vouchers/groups/" + groupId + "/export-pdf");
            DownloadManager.Request request = new DownloadManager.Request(uri);
            request.setTitle("ONLIFI vouchers");
            request.setDescription("Downloading " + safeName + ".pdf");
            request.addRequestHeader("Authorization", "Bearer " + token);
            if (selectedSiteId > 0) request.addRequestHeader("X-Site-ID", String.valueOf(selectedSiteId));
            request.addRequestHeader("Accept", "application/pdf");
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, safeName + ".pdf");
            DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            if (manager != null) {
                manager.enqueue(request);
                toast("Downloading vouchers to Downloads.");
            }
        } catch (Exception e) {
            alert("Download failed", e.getMessage());
        }
    }

    private String sanitizeFileName(String raw) {
        String name = raw == null ? "onlifi-vouchers" : raw.trim().toLowerCase(Locale.US);
        name = name.replaceAll("[^a-z0-9._-]+", "-");
        name = name.replaceAll("-+", "-");
        if (name.length() < 3) return "onlifi-vouchers";
        return name;
    }

    private void requestDownloadPermissionsIfNeeded() {
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, REQUEST_DOWNLOAD_PERMISSIONS);
            return;
        }
        if (Build.VERSION.SDK_INT <= 28 && checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE}, REQUEST_DOWNLOAD_PERMISSIONS);
        }
    }

    private void showWithdrawDialog() {
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(8), 0, dp(8), 0);

        EditText amount = input("Amount UGX", false);
        amount.setInputType(InputType.TYPE_CLASS_NUMBER);
        EditText phone = input("Phone number", false);
        phone.setInputType(InputType.TYPE_CLASS_PHONE);
        TextView note = text("ONLIFI-Laravel does not yet expose a withdrawal request endpoint. The included requirements MD describes the exact endpoint the mobile app needs.", 13, COLOR_MUTED, false);
        note.setPadding(0, dp(12), 0, 0);

        form.addView(label("Amount"));
        form.addView(amount, matchWrapMargin(0, 0, 0, dp(12)));
        form.addView(label("Mobile money number"));
        form.addView(phone, matchWrapMargin(0, 0, 0, dp(12)));
        form.addView(note, matchWrap());

        new AlertDialog.Builder(this)
                .setTitle("Withdraw Money")
                .setView(form)
                .setPositiveButton("OK", null)
                .show();
    }

    private JSONObject request(String method, String endpoint, JSONObject body, boolean auth, int siteId) throws Exception {
        URL url = new URL(API_BASE + endpoint);
        HttpURLConnection connection = (HttpURLConnection) url.openConnection();
        connection.setConnectTimeout(12_000);
        connection.setReadTimeout(18_000);
        connection.setRequestMethod(method);
        connection.setRequestProperty("Accept", "application/json");
        connection.setRequestProperty("Content-Type", "application/json");
        if (auth && token != null) connection.setRequestProperty("Authorization", "Bearer " + token);
        if (siteId > 0) connection.setRequestProperty("X-Site-ID", String.valueOf(siteId));

        if (body != null) {
            connection.setDoOutput(true);
            byte[] bytes = body.toString().getBytes(StandardCharsets.UTF_8);
            OutputStream out = connection.getOutputStream();
            out.write(bytes);
            out.flush();
            out.close();
        }

        int status = connection.getResponseCode();
        InputStream stream = status >= 200 && status < 300 ? connection.getInputStream() : connection.getErrorStream();
        String response = readStream(stream);
        connection.disconnect();

        String trimmed = response == null ? "" : response.trim();
        JSONObject json;
        if (trimmed.isEmpty()) {
            json = new JSONObject();
        } else if (trimmed.startsWith("[")) {
            json = new JSONObject().put("data", new JSONArray(trimmed));
        } else {
            json = new JSONObject(trimmed);
        }
        if (status < 200 || status >= 300) {
            String message = json.optString("message", json.optString("error", "Request failed: " + status));
            throw new Exception(message);
        }
        return json;
    }

    private String readStream(InputStream stream) throws Exception {
        if (stream == null) return "";
        BufferedReader reader = new BufferedReader(new InputStreamReader(stream, StandardCharsets.UTF_8));
        StringBuilder builder = new StringBuilder();
        String line;
        while ((line = reader.readLine()) != null) builder.append(line);
        reader.close();
        return builder.toString();
    }

    private JSONObject readCache(String key) {
        String raw = preferences.getString("cache:" + key, null);
        if (raw == null) return null;
        try {
            return new JSONObject(raw);
        } catch (JSONException e) {
            return null;
        }
    }

    private void writeCache(String key, JSONObject value) {
        preferences.edit().putString("cache:" + key, value.toString()).apply();
    }

    private boolean isNetworkAvailable() {
        ConnectivityManager manager = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
        if (manager == null) return true;
        NetworkInfo network = manager.getActiveNetworkInfo();
        return network != null && network.isConnected();
    }

    private String readUserName() {
        try {
            JSONObject user = new JSONObject(preferences.getString(PREF_USER, "{}"));
            return user.optString("name", user.optString("email", "ONLIFI user"));
        } catch (JSONException e) {
            return "ONLIFI user";
        }
    }

    private LinearLayout page() {
        LinearLayout page = new LinearLayout(this);
        page.setOrientation(LinearLayout.VERTICAL);
        page.setPadding(dp(16), dp(18), dp(16), dp(20));
        return page;
    }

    private LinearLayout card() {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(16), dp(16), dp(16), dp(16));
        card.setBackground(rounded(COLOR_CARD, 1, COLOR_BORDER, dp(8)));
        return card;
    }

    private LinearLayout taggedDetail() {
        LinearLayout detail = new LinearLayout(this);
        detail.setTag("account_detail");
        detail.setOrientation(LinearLayout.VERTICAL);
        return detail;
    }

    private View metricCard(String title, String value, String caption, int accent) {
        LinearLayout card = card();
        TextView titleView = text(title, 12, COLOR_MUTED, true);
        TextView valueView = text(value, 22, accent, true);
        valueView.setSingleLine(false);
        TextView captionView = text(caption, 12, COLOR_MUTED, false);
        card.addView(titleView, matchWrap());
        card.addView(valueView, matchWrapMargin(0, dp(8), 0, dp(4)));
        card.addView(captionView, matchWrap());
        return card;
    }

    private View clientRow(JSONObject row) {
        if (row == null) row = new JSONObject();
        String voucher = firstNonEmpty(row.optString("voucher_code"), row.optString("username"), "-");
        String ip = firstNonEmpty(row.optString("ip_address"), "-");
        String timeLeft = firstNonEmpty(row.optString("time_left"), row.optString("remaining_time"), row.optString("uptime"), "-");

        LinearLayout rowView = rowShell();
        TextView icon = iconBadge("V", COLOR_PRIMARY);
        rowView.addView(icon, new LinearLayout.LayoutParams(dp(36), dp(36)));
        rowView.addView(column(voucher, 14, COLOR_INK, true), new LinearLayout.LayoutParams(0, -2, 1.1f));
        rowView.addView(column(ip, 12, COLOR_MUTED, false), new LinearLayout.LayoutParams(0, -2, 1f));
        rowView.addView(column(timeLeft, 12, COLOR_ORANGE, true), new LinearLayout.LayoutParams(0, -2, 0.8f));
        return rowView;
    }

    private View transactionRow(JSONObject row) {
        if (row == null) row = new JSONObject();
        LinearLayout rowView = rowShell();
        rowView.addView(iconBadge("P", COLOR_BLUE), new LinearLayout.LayoutParams(dp(36), dp(36)));
        rowView.addView(column(firstNonEmpty(row.optString("msisdn"), "-"), 13, COLOR_INK, true), new LinearLayout.LayoutParams(0, -2, 1.2f));
        rowView.addView(column(firstNonEmpty(row.optString("voucher_code"), "-"), 12, COLOR_MINT, true), new LinearLayout.LayoutParams(0, -2, 1f));
        TextView amount = column(money(optDouble(row, "amount")), 12, COLOR_ORANGE, true);
        amount.setGravity(Gravity.RIGHT | Gravity.CENTER_VERTICAL);
        rowView.addView(amount, new LinearLayout.LayoutParams(0, -2, 1f));
        return rowView;
    }

    private View activeUserHeader() {
        LinearLayout row = headerShell();
        row.addView(text("", 11, COLOR_MUTED, true), new LinearLayout.LayoutParams(dp(36), -2));
        row.addView(column("Voucher Code", 11, COLOR_MUTED, true), new LinearLayout.LayoutParams(0, -2, 1.1f));
        row.addView(column("IP Address", 11, COLOR_MUTED, true), new LinearLayout.LayoutParams(0, -2, 1f));
        row.addView(column("Time Left", 11, COLOR_MUTED, true), new LinearLayout.LayoutParams(0, -2, 0.8f));
        return row;
    }

    private View transactionHeader() {
        LinearLayout row = headerShell();
        row.addView(text("", 11, COLOR_MUTED, true), new LinearLayout.LayoutParams(dp(36), -2));
        row.addView(column("Phone", 11, COLOR_MUTED, true), new LinearLayout.LayoutParams(0, -2, 1.2f));
        row.addView(column("Voucher", 11, COLOR_MUTED, true), new LinearLayout.LayoutParams(0, -2, 1f));
        TextView amount = column("Amount", 11, COLOR_MUTED, true);
        amount.setGravity(Gravity.RIGHT | Gravity.CENTER_VERTICAL);
        row.addView(amount, new LinearLayout.LayoutParams(0, -2, 1f));
        return row;
    }

    private LinearLayout rowShell() {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        row.setPadding(dp(10), dp(10), dp(10), dp(10));
        row.setBackground(rounded(COLOR_CARD_SOFT, 1, COLOR_BORDER, dp(8)));
        return row;
    }

    private LinearLayout headerShell() {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        row.setPadding(dp(10), dp(4), dp(10), dp(4));
        return row;
    }

    private TextView column(String value, int sp, int color, boolean bold) {
        TextView view = text(value, sp, color, bold);
        view.setSingleLine(true);
        view.setPadding(dp(8), 0, dp(4), 0);
        return view;
    }

    private TextView iconBadge(String value, int color) {
        TextView badge = text(value, 13, COLOR_BG, true);
        badge.setGravity(Gravity.CENTER);
        badge.setBackground(rounded(color, 0, color, dp(18)));
        return badge;
    }

    private View simpleRow(String title, String detail) {
        LinearLayout row = card();
        row.setPadding(dp(14), dp(12), dp(14), dp(12));
        row.addView(text(title, 15, COLOR_INK, true), matchWrap());
        row.addView(text(detail, 12, COLOR_MUTED, false), matchWrapMargin(0, dp(3), 0, 0));
        return row;
    }

    private void addSingleMetricGraph(LinearLayout holder, JSONArray rows, String key, int color) {
        double max = 0;
        int count = Math.min(rows.length(), 10);
        for (int i = 0; i < count; i++) {
            max = Math.max(max, optDouble(rows.optJSONObject(i), key));
        }
        for (int i = 0; i < count; i++) {
            JSONObject row = rows.optJSONObject(i);
            double value = optDouble(row, key);
            holder.addView(barRow(row.optString("label", ""), value, max, color), matchWrapMargin(0, 0, 0, dp(8)));
        }
    }

    private void addCombinedGraph(LinearLayout holder, JSONArray rows) {
        double max = 0;
        int count = Math.min(rows.length(), 10);
        for (int i = 0; i < count; i++) {
            JSONObject row = rows.optJSONObject(i);
            max = Math.max(max, optDouble(row, "mobile_money_total") + optDouble(row, "voucher_total"));
        }
        for (int i = 0; i < count; i++) {
            JSONObject row = rows.optJSONObject(i);
            double mobile = optDouble(row, "mobile_money_total");
            double vouchers = optDouble(row, "voucher_total");
            holder.addView(stackedBarRow(row.optString("label", ""), mobile, vouchers, max), matchWrapMargin(0, 0, 0, dp(8)));
        }
    }

    private View barRow(String label, double value, double max, int color) {
        LinearLayout row = card();
        row.setPadding(dp(12), dp(10), dp(12), dp(10));
        row.addView(text(label + "  " + money(value), 12, COLOR_INK, true), matchWrapMargin(0, 0, 0, dp(8)));
        LinearLayout track = new LinearLayout(this);
        track.setOrientation(LinearLayout.HORIZONTAL);
        track.setBackground(rounded(COLOR_BG, 0, COLOR_BG, dp(6)));
        int weight = max <= 0 ? 0 : Math.max(5, (int) Math.round((value / max) * 100));
        TextView fill = new TextView(this);
        fill.setBackground(rounded(color, 0, color, dp(6)));
        track.addView(fill, new LinearLayout.LayoutParams(0, dp(12), weight));
        track.addView(new TextView(this), new LinearLayout.LayoutParams(0, dp(12), Math.max(1, 100 - weight)));
        row.addView(track, matchWrap());
        return row;
    }

    private View stackedBarRow(String label, double mobile, double vouchers, double max) {
        LinearLayout row = card();
        row.setPadding(dp(12), dp(10), dp(12), dp(10));
        row.addView(text(label + "  " + money(mobile + vouchers), 12, COLOR_INK, true), matchWrapMargin(0, 0, 0, dp(8)));
        LinearLayout track = new LinearLayout(this);
        track.setOrientation(LinearLayout.HORIZONTAL);
        track.setBackground(rounded(COLOR_BG, 0, COLOR_BG, dp(6)));
        int mobileWeight = max <= 0 ? 0 : Math.max(0, (int) Math.round((mobile / max) * 100));
        int voucherWeight = max <= 0 ? 0 : Math.max(0, (int) Math.round((vouchers / max) * 100));
        if (mobileWeight + voucherWeight > 0 && mobileWeight + voucherWeight < 5) mobileWeight = 5;
        TextView mobileFill = new TextView(this);
        mobileFill.setBackground(rounded(COLOR_BLUE, 0, COLOR_BLUE, dp(6)));
        TextView voucherFill = new TextView(this);
        voucherFill.setBackground(rounded(COLOR_ORANGE, 0, COLOR_ORANGE, dp(6)));
        track.addView(mobileFill, new LinearLayout.LayoutParams(0, dp(12), mobileWeight));
        track.addView(voucherFill, new LinearLayout.LayoutParams(0, dp(12), voucherWeight));
        track.addView(new TextView(this), new LinearLayout.LayoutParams(0, dp(12), Math.max(1, 100 - mobileWeight - voucherWeight)));
        row.addView(track, matchWrap());
        row.addView(text("Mobile money " + money(mobile) + "   Vouchers " + money(vouchers), 11, COLOR_MUTED, false), matchWrapMargin(0, dp(6), 0, 0));
        return row;
    }

    private TextView sectionTitle(String value) {
        TextView view = text(value, 18, COLOR_INK, true);
        view.setPadding(0, dp(18), 0, dp(10));
        return view;
    }

    private TextView emptyText(String value) {
        TextView view = text(value, 14, COLOR_MUTED, false);
        view.setGravity(Gravity.CENTER);
        view.setPadding(dp(12), dp(24), dp(12), dp(24));
        view.setBackground(rounded(COLOR_CARD_SOFT, 1, COLOR_BORDER, dp(8)));
        return view;
    }

    private TextView errorText(String value) {
        TextView view = text(value == null ? "Request failed." : value, 14, Color.rgb(176, 42, 42), false);
        view.setPadding(dp(12), dp(16), dp(12), dp(16));
        view.setBackground(rounded(COLOR_CARD_SOFT, 1, COLOR_BORDER, dp(8)));
        return view;
    }

    private TextView text(String value, int sp, int color, boolean bold) {
        TextView text = new TextView(this);
        text.setText(value);
        text.setTextSize(sp);
        text.setTextColor(color);
        text.setIncludeFontPadding(true);
        if (bold) text.setTypeface(Typeface.DEFAULT_BOLD);
        return text;
    }

    private TextView label(String value) {
        TextView label = text(value, 12, COLOR_MUTED, true);
        label.setPadding(0, 0, 0, dp(6));
        return label;
    }

    private EditText input(String hint, boolean password) {
        EditText input = new EditText(this);
        input.setHint(hint);
        input.setTextSize(15);
        input.setSingleLine(!hint.toLowerCase(Locale.US).contains("describe"));
        input.setPadding(dp(12), dp(10), dp(12), dp(10));
        input.setBackground(rounded(COLOR_BG, 1, COLOR_BORDER, dp(8)));
        input.setTextColor(COLOR_INK);
        input.setHintTextColor(COLOR_MUTED);
        if (password) input.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        return input;
    }

    private Spinner spinner(String[] values) {
        Spinner spinner = new Spinner(this);
        ArrayAdapter<String> adapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, values);
        adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        spinner.setAdapter(adapter);
        return spinner;
    }

    private Button primaryButton(String label) {
        Button button = new Button(this);
        button.setText(label);
        button.setTextColor(Color.WHITE);
        button.setTextSize(14);
        button.setTypeface(Typeface.DEFAULT_BOLD);
        button.setAllCaps(false);
        button.setBackground(rounded(COLOR_PRIMARY, 0, COLOR_PRIMARY, dp(8)));
        return button;
    }

    private Button outlineButton(String label) {
        Button button = new Button(this);
        button.setText(label);
        button.setTextColor(COLOR_PRIMARY);
        button.setTextSize(14);
        button.setTypeface(Typeface.DEFAULT_BOLD);
        button.setAllCaps(false);
        button.setBackground(rounded(COLOR_CARD_SOFT, 1, COLOR_BORDER, dp(8)));
        return button;
    }

    private GradientDrawable rounded(int color, int strokeWidth, int strokeColor, int radius) {
        GradientDrawable drawable = new GradientDrawable();
        drawable.setColor(color);
        drawable.setCornerRadius(radius);
        if (strokeWidth > 0) drawable.setStroke(strokeWidth, strokeColor);
        return drawable;
    }

    private void setLoading(boolean loading) {
        if (loadingBar != null) loadingBar.setVisibility(loading ? View.VISIBLE : View.GONE);
    }

    private LinearLayout.LayoutParams matchWrap() {
        return new LinearLayout.LayoutParams(-1, -2);
    }

    private LinearLayout.LayoutParams matchWrapMargin(int left, int top, int right, int bottom) {
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(-1, -2);
        params.setMargins(left, top, right, bottom);
        return params;
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }

    private String money(double amount) {
        NumberFormat format = NumberFormat.getNumberInstance(Locale.US);
        return "UGX " + format.format(Math.round(amount));
    }

    private double optDouble(JSONObject object, String key) {
        if (object == null) return 0d;
        return object.optDouble(key, 0d);
    }

    private int intValue(JSONObject object, String key, int fallback) {
        if (object == null || !object.has(key)) return fallback;
        return object.optInt(key, fallback);
    }

    private int parseInt(String raw, int fallback) {
        try {
            return Integer.parseInt(raw.trim());
        } catch (Exception e) {
            return fallback;
        }
    }

    private String firstNonEmpty(String... values) {
        for (String value : values) {
            if (value != null && !value.trim().isEmpty()) return value.trim();
        }
        return "";
    }

    private void alert(String title, String message) {
        new AlertDialog.Builder(this)
                .setTitle(title)
                .setMessage(message == null ? "" : message)
                .setPositiveButton("OK", null)
                .show();
    }

    private void toast(String value) {
        Toast.makeText(this, value, Toast.LENGTH_LONG).show();
    }

    private static class Site {
        final int id;
        final String name;

        Site(int id, String name) {
            this.id = id;
            this.name = name;
        }
    }

    private static class VoucherType {
        final int id;
        final String name;
        final int hours;
        final int minutes;
        final double price;

        VoucherType(int id, String name, int hours, int minutes, double price) {
            this.id = id;
            this.name = name;
            this.hours = Math.max(1, hours);
            this.minutes = minutes > 0 ? minutes : this.hours * 60;
            this.price = price;
        }
    }

    private static class SalesPoint {
        final int id;
        final String name;

        SalesPoint(int id, String name) {
            this.id = id;
            this.name = name;
        }
    }
}
