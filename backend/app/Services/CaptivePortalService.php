<?php

namespace App\Services;

use App\Models\CaptivePortalTemplate;
use App\Models\Site;
use App\Models\SystemSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CaptivePortalService
{
    public function templates(): array
    {
        return [
            [
                'theme' => 'default-theme',
                'name' => 'Default Theme',
                'description' => 'Original OLD-Flow layout with the classic vertical package table',
                'design' => [
                    'site_display_name' => '',
                    'subtitle' => 'Faster, Affordable Internet with a Smile',
                    'pricing_title' => "Don't have a voucher? Buy with Mobile Money",
                    'marquee_text' => '',
                    'support_contact' => '0788770102 or 0704169987',
                    'primary_color' => '#2a5298',
                    'secondary_color' => '#1e3c72',
                    'accent_color' => '#ff6b35',
                    'package_layout' => 'table',
                    'packages' => $this->defaultPackages(),
                    'features' => $this->defaultFeatures(),
                ],
            ],
            [
                'theme' => 'glassy',
                'name' => 'Glassy',
                'description' => 'Same hotspot/payment flow with a translucent glass style',
                'design' => [
                    'site_display_name' => '',
                    'subtitle' => 'Faster, Affordable Internet with a Smile',
                    'pricing_title' => "Don't have a voucher? Buy with Mobile Money",
                    'marquee_text' => '',
                    'support_contact' => '0788770102 or 0704169987',
                    'primary_color' => '#67e8f9',
                    'secondary_color' => '#172554',
                    'accent_color' => '#c4b5fd',
                    'package_layout' => 'table',
                    'packages' => $this->defaultPackages(),
                    'features' => $this->defaultFeatures(),
                ],
            ],
            [
                'theme' => 'square-grid',
                'name' => 'Square Grid',
                'description' => 'Same hotspot/payment flow with two package cards per row',
                'design' => [
                    'site_display_name' => '',
                    'subtitle' => 'Faster, Affordable Internet with a Smile',
                    'pricing_title' => "Don't have a voucher? Buy with Mobile Money",
                    'marquee_text' => '',
                    'support_contact' => '0788770102 or 0704169987',
                    'primary_color' => '#0f766e',
                    'secondary_color' => '#042f2e',
                    'accent_color' => '#f59e0b',
                    'package_layout' => 'grid',
                    'packages' => $this->defaultPackages(),
                    'features' => $this->defaultFeatures(),
                ],
            ],
        ];
    }

    public function activeTemplateForTenant(Tenant $tenant, ?Site $site = null): array
    {
        $template = CaptivePortalTemplate::where('tenant_id', $tenant->id)
            ->when(
                $site && Schema::connection('central')->hasColumn('captive_portal_templates', 'site_id'),
                fn ($query) => $query->where('site_id', $site->id)
            )
            ->where('is_active', true)
            ->latest()
            ->first();

        if ($template) {
            $theme = $template->theme === 'old-flow' ? 'default-theme' : $template->theme;
            return [
                'id' => $template->id,
                'name' => $template->name,
                'theme' => $theme,
                'design' => $this->designForTheme($theme, $template->design ?? []),
            ];
        }

        $theme = (string) SystemSetting::get('default_captive_theme', 'default-theme');
        $theme = $theme === 'old-flow' ? 'default-theme' : $theme;
        $default = collect($this->templates())->firstWhere('theme', $theme) ?: $this->templates()[0];

        return [
            'id' => null,
            'name' => $default['name'],
            'theme' => $default['theme'],
            'design' => $default['design'],
        ];
    }

    private function designForTheme(string $theme, array $overrides = []): array
    {
        $theme = $theme === 'old-flow' ? 'default-theme' : $theme;
        $base = collect($this->templates())->firstWhere('theme', $theme)
            ?: collect($this->templates())->firstWhere('theme', 'default-theme')
            ?: $this->templates()[0];

        return array_replace_recursive($base['design'], $overrides);
    }

    private function defaultFeatures(): array
    {
        return [
            'show_logo' => true,
            'show_marquee' => true,
            'show_find_voucher' => true,
            'show_trial' => true,
            'show_footer' => true,
            'show_payment_modal' => true,
        ];
    }

    private function defaultPackages(): array
    {
        return [
            [
                'duration' => '3 Hours',
                'description' => '',
                'display_price' => 'UGX 500',
                'amount' => '492',
                'package_type' => '3hours',
                'package_name' => '3 Hours',
                'is_family_package' => false,
            ],
            [
                'duration' => '24 Hours',
                'description' => 'Full Day',
                'display_price' => 'UGX 1,000',
                'amount' => '985',
                'package_type' => '24hours',
                'package_name' => '24 Hours',
                'is_family_package' => false,
            ],
            [
                'duration' => '7 Days',
                'description' => 'One Week',
                'display_price' => 'UGX 6,000',
                'amount' => '5910',
                'package_type' => '7days',
                'package_name' => '7 Days',
                'is_family_package' => false,
            ],
            [
                'duration' => '30 Days',
                'description' => 'One Month',
                'display_price' => 'UGX 25,000',
                'amount' => '25000',
                'package_type' => '30days',
                'package_name' => '30 Days',
                'is_family_package' => false,
            ],
            [
                'duration' => 'Family (Weekly)',
                'description' => 'Smart TV, 2-3 Devices',
                'display_price' => 'UGX 10,000',
                'amount' => '10000',
                'package_type' => '7days',
                'package_name' => '7 Days',
                'is_family_package' => false,
            ],
            [
                'duration' => 'Family (Monthly)',
                'description' => 'Smart TV, 2-3 Devices',
                'display_price' => 'UGX 40,000',
                'amount' => '40000',
                'package_type' => 'family_monthly',
                'package_name' => 'Family Monthly',
                'is_family_package' => true,
            ],
        ];
    }

    public function configForToken(string $token): ?array
    {
        $nas = DB::connection('central')->table('nas')
            ->where('provisioning_token', $token)
            ->first();

        if (!$nas) {
            return null;
        }

        $tenant = Tenant::find($nas->tenant_id);
        if (!$tenant || !$tenant->canAccess()) {
            return null;
        }

        $site = null;
        if (Schema::connection('central')->hasColumn('nas', 'site_id') && !empty($nas->site_id)) {
            $site = \App\Models\Site::where('tenant_id', $tenant->id)->where('id', $nas->site_id)->first();
        }
        if ($site) {
            if (!$site->database_name) {
                $site->provisionDatabase($tenant);
                $site = $site->fresh();
            }
            $site->configureTenantConnection($tenant);
        } else {
            $tenant->configure();
        }
        $siteName = $site?->name ?: ($nas->shortname ?: $tenant->name);

        $packages = DB::connection('tenant')->table('voucher_groups')
            ->select('group_name as name', 'price', 'validity_hours', 'data_limit_mb', 'speed_limit_kbps')
            ->when($site && Schema::connection('tenant')->hasColumn('voucher_groups', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
            ->orderBy('price')
            ->limit(12)
            ->get();

        if ($packages->isEmpty()) {
            $packages = collect([
                ['name' => 'Quick Access', 'price' => 1000, 'validity_hours' => 6, 'data_limit_mb' => null, 'speed_limit_kbps' => null],
                ['name' => 'Daily Access', 'price' => 2000, 'validity_hours' => 24, 'data_limit_mb' => null, 'speed_limit_kbps' => null],
            ]);
        }

        return [
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'router' => [
                'name' => $siteName,
                'identifier' => $nas->router_identifier,
                'token' => $token,
            ],
            'template' => $this->activeTemplateForTenant($tenant, $site),
            'api' => [
                'base_url' => $this->apiBaseUrl(),
                'pay_url' => $this->apiBaseUrl() . '/api/captive/pay',
                'status_url' => $this->apiBaseUrl() . '/api/captive/payment-status',
            ],
            'manual_payment' => [
                'site_name' => $siteName,
                'site_slug' => $this->paymentSiteSlug($siteName),
                'destination_directory' => $this->paymentSiteSlug($siteName),
                'initiate_url' => $this->manualPaymentUrl($siteName),
                'check_status_url' => $this->manualPaymentUrl($siteName, 'check_status.php'),
                'voucher_lookup_url' => $this->manualPaymentUrl($siteName, 'look/voucher-lookup.php'),
            ],
            'packages' => $packages->values(),
        ];
    }

    public function hotspotFile(string $token, string $file): ?string
    {
        $config = $this->configForToken($token);
        if (!$config) {
            return null;
        }

        return match ($file) {
            'login.html' => $this->loginHtml($config),
            'status.html' => $this->simpleHtml($config, 'Connected', 'You are connected to OnLiFi WiFi.'),
            'alogin.html' => $this->simpleHtml($config, 'Login Successful', 'Your voucher is active. You can start browsing.'),
            'md5.js' => $this->mikrotikMd5Js(),
            default => null,
        };
    }

    public function previewLoginHtml(Tenant $tenant, ?Site $site, ?array $template = null): string
    {
        $siteName = $site?->name ?: $tenant->name;
        $activeTemplate = $template ?: $this->activeTemplateForTenant($tenant, $site);
        $theme = $activeTemplate['theme'] ?? 'default-theme';
        $activeTemplate['theme'] = $theme === 'old-flow' ? 'default-theme' : $theme;
        $activeTemplate['design'] = $this->designForTheme($activeTemplate['theme'], $activeTemplate['design'] ?? []);

        return $this->loginHtml([
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'router' => [
                'name' => $siteName,
                'identifier' => Str::slug($siteName) . '-ONLIFI-1',
                'token' => 'preview',
            ],
            'template' => $activeTemplate,
            'api' => [
                'base_url' => $this->apiBaseUrl(),
                'pay_url' => $this->apiBaseUrl() . '/api/captive/pay',
                'status_url' => $this->apiBaseUrl() . '/api/captive/payment-status',
            ],
            'manual_payment' => [
                'site_name' => $siteName,
                'site_slug' => $this->paymentSiteSlug($siteName),
                'destination_directory' => $this->paymentSiteSlug($siteName),
                'initiate_url' => $this->manualPaymentUrl($siteName),
                'check_status_url' => $this->manualPaymentUrl($siteName, 'check_status.php'),
                'voucher_lookup_url' => $this->manualPaymentUrl($siteName, 'look/voucher-lookup.php'),
            ],
            'packages' => collect(),
        ]);
    }

    public function downloadLoginHtml(Tenant $tenant, ?Site $site, ?array $template = null): string
    {
        return $this->previewLoginHtml($tenant, $site, $template);
    }

    private function loginHtml(array $config): string
    {
        $template = $this->loadManualLoginTemplate();
        if ($template) {
            return $this->renderManualLoginTemplate($template, $config);
        }

        return $this->missingTemplateHtml($config);
    }

    private function defaultManualLoginHtml(array $config): string
    {
        $design = array_merge([
            'primary_color' => '#2563eb',
            'background_color' => '#f8fafc',
            'text_color' => '#0f172a',
            'headline' => 'Connect to WiFi',
            'subheadline' => 'Buy a voucher with mobile money or enter your voucher code.',
            'button_label' => 'Pay with Mobile Money',
            'logo_url' => '',
        ], $config['template']['design'] ?? []);

        $jsonConfig = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $primary = htmlspecialchars($design['primary_color'], ENT_QUOTES);
        $background = htmlspecialchars($design['background_color'], ENT_QUOTES);
        $text = htmlspecialchars($design['text_color'], ENT_QUOTES);
        $headline = htmlspecialchars($design['headline'], ENT_QUOTES);
        $subheadline = htmlspecialchars($design['subheadline'], ENT_QUOTES);
        $buttonLabel = htmlspecialchars($design['button_label'], ENT_QUOTES);
        $logoUrl = htmlspecialchars($design['logo_url'] ?? '', ENT_QUOTES);
        $logoHtml = $logoUrl ? "<img src=\"{$logoUrl}\" alt=\"Logo\" class=\"logo\">" : '';
        $siteName = htmlspecialchars($config['manual_payment']['site_name'], ENT_QUOTES);
        $siteSlug = htmlspecialchars($config['manual_payment']['site_slug'], ENT_QUOTES);
        $initiateUrl = htmlspecialchars($config['manual_payment']['initiate_url'], ENT_QUOTES);

        return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$headline}</title>
  <style>
    :root{--primary:{$primary};--bg:{$background};--text:{$text};}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);display:grid;place-items:center;padding:18px}
    .wrap{width:100%;max-width:440px;background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:12px;padding:22px;box-shadow:0 20px 50px rgba(15,23,42,.14)}.logo{max-width:150px;max-height:70px;object-fit:contain;margin-bottom:12px}
    h1{margin:0 0 6px;font-size:26px}.muted{color:#64748b;font-size:14px;line-height:1.45}.tabs{display:flex;gap:8px;margin:18px 0}
    button,.tab{border:0;border-radius:8px;padding:11px 13px;font-weight:700;cursor:pointer}.tab{flex:1;background:#e2e8f0}.tab.active,button.primary{background:var(--primary);color:#fff}
    input,select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;margin:8px 0 12px;font-size:15px}.row{display:grid;gap:10px}.hide{display:none}.msg{font-size:14px;margin-top:10px}.ok{color:#047857}.err{color:#b91c1c}
    .package{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px;border:1px solid #e2e8f0;border-radius:8px;margin:8px 0;background:#f8fafc}
    .tenant{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:4px}
  </style>
</head>
<body>
  <div class="wrap">
    {$logoHtml}
    <div class="tenant" id="tenantName"></div>
    <h1>{$headline}</h1>
    <p class="muted">{$subheadline}</p>
    <div class="tabs"><button class="tab active" onclick="showTab('pay')">Mobile Money</button><button class="tab" onclick="showTab('voucher')">Voucher</button></div>
    <section id="payTab">
      <form id="paymentForm" action="{$initiateUrl}" method="post">
        <input type="hidden" name="Site-Name" value="{$siteName}">
        <input type="hidden" name="site_name" value="{$siteName}">
        <input type="hidden" name="site_slug" value="{$siteSlug}">
        <input type="hidden" name="mac" value="\$(mac)">
        <input type="hidden" name="origin_url" value="\$(link-orig)">
        <input type="hidden" name="link_login_only" value="\$(link-login-only)">
        <div id="packages"></div>
        <input type="hidden" id="packageName" name="package" value="">
        <input type="hidden" id="amount" name="amount" value="">
      <input id="phone" inputmode="tel" placeholder="Mobile money number e.g. 2567XXXXXXXX">
        <input type="hidden" id="msisdn" name="msisdn" value="">
        <button class="primary" style="width:100%" type="submit">{$buttonLabel}</button>
      </form>
      <div id="payMsg" class="msg"></div>
    </section>
    <section id="voucherTab" class="hide">
      <form name="login" action="\$(link-login-only)" method="post">
        <input type="hidden" name="dst" value="\$(link-orig)">
        <input type="hidden" name="popup" value="true">
        <input name="username" id="voucher" placeholder="Voucher code">
        <input name="password" id="password" placeholder="Password or voucher code">
        <button class="primary" style="width:100%" type="submit">Connect</button>
      </form>
    </section>
  </div>
  <script>
    const ONLIFI = {$jsonConfig};
    let selectedPackage = null;
    let pollRef = null;
    document.getElementById('tenantName').textContent = ONLIFI.tenant.name;
    function showTab(tab){document.getElementById('payTab').classList.toggle('hide',tab!=='pay');document.getElementById('voucherTab').classList.toggle('hide',tab!=='voucher');document.querySelectorAll('.tab').forEach((b,i)=>b.classList.toggle('active',(tab==='pay'&&i===0)||(tab==='voucher'&&i===1)));}
    function renderPackages(){const holder=document.getElementById('packages');holder.innerHTML='';ONLIFI.packages.forEach((p,i)=>{if(i===0)selectedPackage=p;const row=document.createElement('label');row.className='package';row.innerHTML='<span><strong>'+p.name+'</strong><br><small>'+p.validity_hours+' hours</small></span><span><input type="radio" name="pkg" '+(i===0?'checked':'')+'> UGX '+Number(p.price).toLocaleString()+'</span>';row.onclick=()=>selectedPackage=p;holder.appendChild(row);});}
    document.getElementById('paymentForm').addEventListener('submit',function(){document.getElementById('msisdn').value=document.getElementById('phone').value;if(selectedPackage){document.getElementById('packageName').value=selectedPackage.name;document.getElementById('amount').value=selectedPackage.price;}});
    renderPackages();
  </script>
</body>
</html>
HTML;
    }

    private function loadManualLoginTemplate(): ?string
    {
        $paths = [
            base_path('../OLD-Flow/login.html'),
            base_path('OLD-Flow/login.html'),
            base_path('EgoSMS Flow/login.html'),
            resource_path('hotspot/login-manual.html'),
            resource_path('hotspot/login.html'),
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $contents = file_get_contents($path);
                if ($contents !== false) {
                    return $contents;
                }
            }
        }

        return null;
    }

    private function renderManualLoginTemplate(string $template, array $config): string
    {
        $siteName = $config['manual_payment']['site_name'];
        $theme = $config['template']['theme'] ?? 'default-theme';
        $design = $this->designForTheme($theme, $config['template']['design'] ?? []);
        $features = array_replace($this->defaultFeatures(), is_array($design['features'] ?? null) ? $design['features'] : []);
        $packages = is_array($design['packages'] ?? null) ? $design['packages'] : $this->defaultPackages();
        $displayName = trim((string) ($design['site_display_name'] ?? '')) ?: $siteName;
        $marqueeText = trim((string) ($design['marquee_text'] ?? ''));

        $replacements = [
            '{{SITE_NAME}}' => $siteName,
            '{{SITE_SLUG}}' => $config['manual_payment']['site_slug'],
            '{{DESTINATION_DIRECTORY}}' => $config['manual_payment']['destination_directory'],
            '{{SITE_NAME_FIELD}}' => $siteName,
            '{{PAYMENT_INITIATE_URL}}' => $config['manual_payment']['initiate_url'],
            '{{MANUAL_PAYMENT_URL}}' => $config['manual_payment']['initiate_url'],
            '{{PAYMENT_CHECK_STATUS_URL}}' => $config['manual_payment']['check_status_url'],
            '{{VOUCHER_LOOKUP_URL}}' => $config['manual_payment']['voucher_lookup_url'],
            '{{API_BASE_URL}}' => $config['api']['base_url'],
            '{{ROUTER_TOKEN}}' => $config['router']['token'],
        ];

        $html = str_replace(array_keys($replacements), array_map('htmlspecialchars', array_values($replacements)), $template);

        $html = str_replace(
            ['SITE_NAME_PLACEHOLDER', 'DESTINATION_DIRECTORY_PLACEHOLDER', 'PAYMENT_ENDPOINT_PLACEHOLDER'],
            [$siteName, $config['manual_payment']['destination_directory'], $config['manual_payment']['initiate_url']],
            $html
        );

        $legacyReplacements = [
            "const CURRENT_ORIGIN_SITE = 'STK WIFI';" => 'const CURRENT_ORIGIN_SITE = ' . json_encode($siteName) . ';',
            'http://pay.onlustech.com/yo/initiate.php' => $config['manual_payment']['initiate_url'],
            'https://pay.onlustech.com/yo/initiate.php' => $config['manual_payment']['initiate_url'],
            'http://pay.onlustech.com/yo/check_status.php' => $config['manual_payment']['check_status_url'],
            'https://pay.onlustech.com/yo/check_status.php' => $config['manual_payment']['check_status_url'],
            'http://pay.onlustech.com/yo/look/voucher-lookup.php' => $config['manual_payment']['voucher_lookup_url'],
            'https://pay.onlustech.com/yo/look/voucher-lookup.php' => $config['manual_payment']['voucher_lookup_url'],
            '<h1>STK WIFI POINT</h1>' => '<h1>' . htmlspecialchars($displayName, ENT_QUOTES) . '</h1>',
            '<p class="subtitle">Faster, Affordable Internet with a Smile</p>' => '<p class="subtitle">' . htmlspecialchars((string) $design['subtitle'], ENT_QUOTES) . '</p>',
            'Need help? Contact: <strong>0788770102 or 0704169987</strong>' => 'Need help? Contact: <strong>' . htmlspecialchars((string) $design['support_contact'], ENT_QUOTES) . '</strong>',
            'Powered by Onlus Technologies' => 'Powered by Onlus Technologies',
            '#2a5298' => $this->safeCssColor((string) $design['primary_color'], '#2a5298'),
            '#1e3c72' => $this->safeCssColor((string) $design['secondary_color'], '#1e3c72'),
            '#ff6b35' => $this->safeCssColor((string) $design['accent_color'], '#ff6b35'),
        ];

        $html = str_replace(array_keys($legacyReplacements), array_values($legacyReplacements), $html);

        if ($marqueeText !== '') {
            $html = preg_replace(
                '/<div class="marquee-content">.*?<\/div>/s',
                '<div class="marquee-content">' . $marqueeText . '</div>',
                $html,
                1
            ) ?? $html;
        }

        $html = $this->replacePricingSection($html, $packages, (string) ($design['package_layout'] ?? 'table'), (string) ($design['pricing_title'] ?? "Don't have a voucher? Buy with Mobile Money"));
        $html = $this->applyFeatureToggles($html, $features);
        $html = $this->applyThemeVariant($html, $theme, $design);

        $html = str_replace(
            ['STK WIFI POINT', 'STK WIFI'],
            [htmlspecialchars($displayName, ENT_QUOTES), htmlspecialchars($siteName, ENT_QUOTES)],
            $html
        );

        return $this->injectVoucherPasswordSyncScript($html);
    }

    private function injectVoucherPasswordSyncScript(string $html): string
    {
        $script = <<<'HTML'

    <script>
        (function () {
            function syncVoucherPassword(form) {
                if (!form || !form.elements) {
                    return true;
                }

                var usernameInput = form.elements['username'];
                var passwordInput = form.elements['password'];

                if (usernameInput && passwordInput) {
                    passwordInput.value = usernameInput.value || '';
                }

                return true;
            }

            function installVoucherPasswordSync() {
                var form = document.forms['login'];
                if (!form || form.__onlifiPasswordSyncInstalled) {
                    return;
                }

                form.__onlifiPasswordSyncInstalled = true;

                form.addEventListener('submit', function () {
                    syncVoucherPassword(form);
                }, true);

                form.addEventListener('click', function () {
                    syncVoucherPassword(form);
                }, true);

                var usernameInput = form.elements['username'];
                if (usernameInput) {
                    usernameInput.addEventListener('input', function () {
                        syncVoucherPassword(form);
                    });
                    usernameInput.addEventListener('change', function () {
                        syncVoucherPassword(form);
                    });
                    usernameInput.addEventListener('blur', function () {
                        syncVoucherPassword(form);
                    });
                }

                var nativeSubmit = form.submit;
                form.submit = function () {
                    syncVoucherPassword(form);
                    return nativeSubmit.call(form);
                };

                syncVoucherPassword(form);
            }

            document.addEventListener('DOMContentLoaded', installVoucherPasswordSync);
            installVoucherPasswordSync();

            window.onlifiSyncVoucherPassword = function (form) {
                return syncVoucherPassword(form || document.forms['login']);
            };
        })();
    </script>
HTML;

        if (str_contains($html, 'window.onlifiSyncVoucherPassword')) {
            return $html;
        }

        return str_ireplace('</body>', $script . "\n</body>", $html);
    }

    private function safeCssColor(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
    }

    private function replacePricingSection(string $html, array $packages, string $layout, string $title): string
    {
        $pricingHtml = $layout === 'grid'
            ? $this->packageGridHtml($packages, $title)
            : $this->packageTableHtml($packages, $title);

        return preg_replace(
            '/<div class="pricing-section">.*?<\/div>\s*<div class="footer">/s',
            $pricingHtml . "\n\n        <div class=\"footer\">",
            $html,
            1
        ) ?? $html;
    }

    private function packageTableHtml(array $packages, string $title): string
    {
        $rows = array_map(fn ($package) => $this->packageTableRow($package), $packages);
        $title = htmlspecialchars($title, ENT_QUOTES);

        return "        <div class=\"pricing-section\">\n            <h2 class=\"pricing-title\">{$title}</h2>\n            <table class=\"pricing-table\">\n" . implode("\n", $rows) . "\n            </table>\n        </div>";
    }

    private function packageTableRow(array $package): string
    {
        $duration = htmlspecialchars((string) ($package['duration'] ?? $package['package_name'] ?? 'Package'), ENT_QUOTES);
        $description = trim((string) ($package['description'] ?? ''));
        $descriptionHtml = $description !== ''
            ? "\n                        <span class=\"package-desc\">" . htmlspecialchars($description, ENT_QUOTES) . '</span>'
            : '';
        $displayPrice = htmlspecialchars((string) ($package['display_price'] ?? 'UGX ' . number_format((float) ($package['amount'] ?? 0))), ENT_QUOTES);
        $button = $this->packageBuyButton($package);

        return <<<HTML
                <tr>
                    <td>
                        <span class="package-duration">{$duration}</span>{$descriptionHtml}
                    </td>
                    <td class="package-price">{$displayPrice}</td>
                    <td>{$button}</td>
                </tr>
HTML;
    }

    private function packageGridHtml(array $packages, string $title): string
    {
        $cards = array_map(fn ($package) => $this->packageGridCard($package), $packages);
        $title = htmlspecialchars($title, ENT_QUOTES);

        return "        <div class=\"pricing-section package-grid-section\">\n            <h2 class=\"pricing-title\">{$title}</h2>\n            <div class=\"package-grid\">\n" . implode("\n", $cards) . "\n            </div>\n        </div>";
    }

    private function packageGridCard(array $package): string
    {
        $duration = htmlspecialchars((string) ($package['duration'] ?? $package['package_name'] ?? 'Package'), ENT_QUOTES);
        $description = htmlspecialchars((string) ($package['description'] ?? ''), ENT_QUOTES);
        $displayPrice = htmlspecialchars((string) ($package['display_price'] ?? 'UGX ' . number_format((float) ($package['amount'] ?? 0))), ENT_QUOTES);
        $button = $this->packageBuyButton($package);

        return <<<HTML
                <div class="package-card">
                    <span class="package-duration">{$duration}</span>
                    <span class="package-desc">{$description}</span>
                    <span class="package-price">{$displayPrice}</span>
                    {$button}
                </div>
HTML;
    }

    private function packageBuyButton(array $package): string
    {
        $amount = $this->jsString((string) ($package['amount'] ?? '0'));
        $type = $this->jsString((string) ($package['package_type'] ?? Str::slug((string) ($package['package_name'] ?? 'package'), '_')));
        $name = $this->jsString((string) ($package['package_name'] ?? $package['duration'] ?? 'Package'));
        $family = !empty($package['is_family_package']) ? ', true' : '';

        return "<button class=\"buy-btn\" onclick=\"openPaymentModal(this, '{$amount}', '{$type}', '{$name}', '$(mac-esc)', '$(link-orig-esc)', '$(link-login-only)'{$family})\">Buy</button>";
    }

    private function jsString(string $value): string
    {
        return str_replace(["\\", "'", "\r", "\n"], ["\\\\", "\\'", '', ' '], $value);
    }

    private function applyFeatureToggles(string $html, array $features): string
    {
        if (empty($features['show_logo'])) {
            $html = preg_replace('/\s*<div class="wifi-icon">.*?<\/div>\s*/s', "\n", $html, 1) ?? $html;
        }
        if (empty($features['show_marquee'])) {
            $html = preg_replace('/\s*<div class="marquee-container">\s*<div class="marquee-content">.*?<\/div>\s*<\/div>\s*/s', "\n", $html, 1) ?? $html;
        }
        if (empty($features['show_find_voucher'])) {
            $html = preg_replace('/\s*<button type="button" class="btn-find-voucher".*?<\/button>\s*/s', "\n", $html, 1) ?? $html;
        }
        if (empty($features['show_trial'])) {
            $html = preg_replace('/\s*\$\(if trial == \'yes\'\).*?\$\(endif\)\s*/s', "\n", $html, 1) ?? $html;
        }
        if (empty($features['show_footer'])) {
            $html = preg_replace('/\s*<div class="footer">.*?<\/div>\s*(?=<\/div>\s*<script>)/s', "\n", $html, 1) ?? $html;
        }
        if (empty($features['show_payment_modal'])) {
            $html = preg_replace('/\s*<div class="pricing-section.*?<\/div>\s*(?=<div class="footer">)/s', "\n", $html, 1) ?? $html;
            $html = preg_replace('/\s*<!-- Payment Modal -->.*?<!-- MikroTik CHAP support/s', "\n    <!-- MikroTik CHAP support", $html, 1) ?? $html;
        }

        return $html;
    }

    private function applyThemeVariant(string $html, string $theme, array $design): string
    {
        $theme = $theme === 'old-flow' ? 'default-theme' : $theme;
        $primary = $this->safeCssColor((string) ($design['primary_color'] ?? '#2a5298'), '#2a5298');
        $secondary = $this->safeCssColor((string) ($design['secondary_color'] ?? '#1e3c72'), '#1e3c72');
        $accent = $this->safeCssColor((string) ($design['accent_color'] ?? '#ff6b35'), '#ff6b35');
        $css = '';

        if ($theme === 'glassy') {
            $css = <<<CSS

        body {
            background:
                radial-gradient(circle at 14% 6%, rgba(103, 232, 249, .64), transparent 31%),
                radial-gradient(circle at 86% 14%, rgba(196, 181, 253, .56), transparent 29%),
                radial-gradient(circle at 48% 100%, rgba(34, 211, 238, .32), transparent 34%),
                linear-gradient(135deg, {$secondary} 0%, #0f172a 48%, #164e63 100%);
            background-attachment: fixed;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(115deg, rgba(255,255,255,.18), transparent 32%, rgba(255,255,255,.10) 58%, transparent 72%),
                radial-gradient(circle at 50% 20%, rgba(255,255,255,.20), transparent 26%);
        }
        .container {
            position: relative;
            overflow: hidden;
            background: linear-gradient(145deg, rgba(255, 255, 255, .20), rgba(255,255,255,.08));
            border: 1px solid rgba(255, 255, 255, .44);
            box-shadow: 0 30px 90px rgba(2, 6, 23, .46), inset 0 1px 0 rgba(255,255,255,.44);
            backdrop-filter: blur(30px) saturate(165%);
            -webkit-backdrop-filter: blur(30px) saturate(165%);
        }
        .container::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(120deg, rgba(255,255,255,.32), transparent 34%, rgba(255,255,255,.10));
        }
        .header {
            position: relative;
            background: linear-gradient(135deg, rgba(255,255,255,.24), rgba(255,255,255,.07));
            border-bottom: 1px solid rgba(255,255,255,.28);
        }
        .form-container, .pricing-section, .footer {
            position: relative;
            background: rgba(255,255,255,.28);
            border-color: rgba(255,255,255,.34);
            color: rgba(15,23,42,.92);
            backdrop-filter: blur(22px) saturate(145%);
            -webkit-backdrop-filter: blur(22px) saturate(145%);
        }
        input[type="text"], input[type="tel"], select, .input-group input {
            background: rgba(255,255,255,.46);
            border-color: rgba(255,255,255,.68);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.46), 0 8px 24px rgba(15,23,42,.08);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        .submit-btn, .buy-btn, .btn-proceed {
            background: linear-gradient(135deg, {$primary} 0%, {$accent} 100%);
            box-shadow: 0 10px 28px rgba(15, 23, 42, .20);
        }
        .pricing-table tr, .package-card {
            background: rgba(255,255,255,.30);
            border: 1px solid rgba(255,255,255,.48);
            box-shadow: 0 12px 34px rgba(15, 23, 42, .14), inset 0 1px 0 rgba(255,255,255,.38);
            backdrop-filter: blur(18px) saturate(140%);
            -webkit-backdrop-filter: blur(18px) saturate(140%);
        }
        .pricing-table tr:hover, .package-card:hover {
            background: rgba(255,255,255,.42);
        }
        .modal {
            background: rgba(255,255,255,.48);
            border: 1px solid rgba(255,255,255,.62);
            box-shadow: 0 24px 70px rgba(2,6,23,.36), inset 0 1px 0 rgba(255,255,255,.42);
            backdrop-filter: blur(28px) saturate(155%);
            -webkit-backdrop-filter: blur(28px) saturate(155%);
        }
CSS;
        }

        if ($theme === 'square-grid') {
            $css .= <<<CSS

        .package-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .package-card {
            min-height: 142px;
            border: 1px solid rgba(42, 82, 152, .16);
            border-radius: 16px;
            padding: 14px;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 8px;
            box-shadow: 0 8px 24px rgba(42, 82, 152, .10);
        }
        .package-card .package-duration {
            color: {$secondary};
            font-size: 14px;
            font-weight: 800;
        }
        .package-card .package-desc {
            min-height: 28px;
            color: #64748b;
            font-size: 11px;
        }
        .package-card .package-price {
            color: {$accent};
            font-size: 17px;
            font-weight: 900;
        }
        .package-card .buy-btn {
            width: 100%;
            padding: 10px;
        }
        @media (max-width: 380px) {
            .package-grid { grid-template-columns: 1fr; }
        }
CSS;
        }

        if ($css === '') {
            return $html;
        }

        return str_replace('</style>', $css . "\n    </style>", $html);
    }

    private function apiBaseUrl(): string
    {
        return rtrim((string) SystemSetting::get('api_base_url', config('app.api_url', config('app.url'))), '/');
    }

    private function manualPaymentUrl(string $siteName, string $script = 'initiate.php'): string
    {
        return rtrim((string) SystemSetting::get('manual_payment_base_url', config('app.manual_payment_base_url')), '/')
            . '/' . $this->paymentSiteSlug($siteName) . '/' . ltrim($script, '/');
    }

    private function paymentSiteSlug(string $siteName): string
    {
        return Str::slug($siteName) ?: 'site';
    }

    private function missingTemplateHtml(array $config): string
    {
        $siteName = htmlspecialchars($config['manual_payment']['site_name'], ENT_QUOTES);

        return "<!doctype html><html><head><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$siteName} Hotspot</title></head><body><h1>{$siteName}</h1><p>OLD-Flow/login.html was not found on the server. Deploy the OLD-Flow folder with the application so this router receives the full captive page.</p></body></html>";
    }

    private function simpleHtml(array $config, string $title, string $message): string
    {
        $tenantName = htmlspecialchars($config['tenant']['name'], ENT_QUOTES);
        $title = htmlspecialchars($title, ENT_QUOTES);
        $message = htmlspecialchars($message, ENT_QUOTES);

        return "<!doctype html><html><head><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$title}</title><style>body{font-family:Arial,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f8fafc;color:#0f172a}.box{max-width:420px;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:white}</style></head><body><div class=\"box\"><small>{$tenantName}</small><h1>{$title}</h1><p>{$message}</p></div></body></html>";
    }

    private function mikrotikMd5Js(): string
    {
        return <<<'JS'
/*
 * MikroTik-compatible MD5 helper for Hotspot CHAP login.
 * Exposes hexMD5(input), matching the function name used by login.html.
 */
function hexMD5(s){return rstr2hex(rstrMD5(str2rstrUTF8(s)));}
function rstrMD5(s){return binl2rstr(binlMD5(rstr2binl(s),s.length*8));}
function rstr2hex(input){var hexTab='0123456789abcdef',output='',x,i;for(i=0;i<input.length;i++){x=input.charCodeAt(i);output+=hexTab.charAt((x>>>4)&15)+hexTab.charAt(x&15);}return output;}
function str2rstrUTF8(input){return unescape(encodeURIComponent(input));}
function rstr2binl(input){var output=Array(input.length>>2),i;for(i=0;i<output.length;i++)output[i]=0;for(i=0;i<input.length*8;i+=8)output[i>>5]|=(input.charCodeAt(i/8)&255)<<(i%32);return output;}
function binl2rstr(input){var output='',i;for(i=0;i<input.length*32;i+=8)output+=String.fromCharCode((input[i>>5]>>>(i%32))&255);return output;}
function safeAdd(x,y){var lsw=(x&65535)+(y&65535),msw=(x>>16)+(y>>16)+(lsw>>16);return(msw<<16)|(lsw&65535);}
function bitRotateLeft(num,cnt){return(num<<cnt)|(num>>>(32-cnt));}
function md5cmn(q,a,b,x,s,t){return safeAdd(bitRotateLeft(safeAdd(safeAdd(a,q),safeAdd(x,t)),s),b);}
function md5ff(a,b,c,d,x,s,t){return md5cmn((b&c)|((~b)&d),a,b,x,s,t);}
function md5gg(a,b,c,d,x,s,t){return md5cmn((b&d)|(c&(~d)),a,b,x,s,t);}
function md5hh(a,b,c,d,x,s,t){return md5cmn(b^c^d,a,b,x,s,t);}
function md5ii(a,b,c,d,x,s,t){return md5cmn(c^(b|(~d)),a,b,x,s,t);}
function binlMD5(x,len){x[len>>5]|=128<<((len)%32);x[(((len+64)>>>9)<<4)+14]=len;var i,olda,oldb,oldc,oldd,a=1732584193,b=-271733879,c=-1732584194,d=271733878;for(i=0;i<x.length;i+=16){olda=a;oldb=b;oldc=c;oldd=d;a=md5ff(a,b,c,d,x[i],7,-680876936);d=md5ff(d,a,b,c,x[i+1],12,-389564586);c=md5ff(c,d,a,b,x[i+2],17,606105819);b=md5ff(b,c,d,a,x[i+3],22,-1044525330);a=md5ff(a,b,c,d,x[i+4],7,-176418897);d=md5ff(d,a,b,c,x[i+5],12,1200080426);c=md5ff(c,d,a,b,x[i+6],17,-1473231341);b=md5ff(b,c,d,a,x[i+7],22,-45705983);a=md5ff(a,b,c,d,x[i+8],7,1770035416);d=md5ff(d,a,b,c,x[i+9],12,-1958414417);c=md5ff(c,d,a,b,x[i+10],17,-42063);b=md5ff(b,c,d,a,x[i+11],22,-1990404162);a=md5ff(a,b,c,d,x[i+12],7,1804603682);d=md5ff(d,a,b,c,x[i+13],12,-40341101);c=md5ff(c,d,a,b,x[i+14],17,-1502002290);b=md5ff(b,c,d,a,x[i+15],22,1236535329);a=md5gg(a,b,c,d,x[i+1],5,-165796510);d=md5gg(d,a,b,c,x[i+6],9,-1069501632);c=md5gg(c,d,a,b,x[i+11],14,643717713);b=md5gg(b,c,d,a,x[i],20,-373897302);a=md5gg(a,b,c,d,x[i+5],5,-701558691);d=md5gg(d,a,b,c,x[i+10],9,38016083);c=md5gg(c,d,a,b,x[i+15],14,-660478335);b=md5gg(b,c,d,a,x[i+4],20,-405537848);a=md5gg(a,b,c,d,x[i+9],5,568446438);d=md5gg(d,a,b,c,x[i+14],9,-1019803690);c=md5gg(c,d,a,b,x[i+3],14,-187363961);b=md5gg(b,c,d,a,x[i+8],20,1163531501);a=md5gg(a,b,c,d,x[i+13],5,-1444681467);d=md5gg(d,a,b,c,x[i+2],9,-51403784);c=md5gg(c,d,a,b,x[i+7],14,1735328473);b=md5gg(b,c,d,a,x[i+12],20,-1926607734);a=md5hh(a,b,c,d,x[i+5],4,-378558);d=md5hh(d,a,b,c,x[i+8],11,-2022574463);c=md5hh(c,d,a,b,x[i+11],16,1839030562);b=md5hh(b,c,d,a,x[i+14],23,-35309556);a=md5hh(a,b,c,d,x[i+1],4,-1530992060);d=md5hh(d,a,b,c,x[i+4],11,1272893353);c=md5hh(c,d,a,b,x[i+7],16,-155497632);b=md5hh(b,c,d,a,x[i+10],23,-1094730640);a=md5hh(a,b,c,d,x[i+13],4,681279174);d=md5hh(d,a,b,c,x[i],11,-358537222);c=md5hh(c,d,a,b,x[i+3],16,-722521979);b=md5hh(b,c,d,a,x[i+6],23,76029189);a=md5hh(a,b,c,d,x[i+9],4,-640364487);d=md5hh(d,a,b,c,x[i+12],11,-421815835);c=md5hh(c,d,a,b,x[i+15],16,530742520);b=md5hh(b,c,d,a,x[i+2],23,-995338651);a=md5ii(a,b,c,d,x[i],6,-198630844);d=md5ii(d,a,b,c,x[i+7],10,1126891415);c=md5ii(c,d,a,b,x[i+14],15,-1416354905);b=md5ii(b,c,d,a,x[i+5],21,-57434055);a=md5ii(a,b,c,d,x[i+12],6,1700485571);d=md5ii(d,a,b,c,x[i+3],10,-1894986606);c=md5ii(c,d,a,b,x[i+10],15,-1051523);b=md5ii(b,c,d,a,x[i+1],21,-2054922799);a=md5ii(a,b,c,d,x[i+8],6,1873313359);d=md5ii(d,a,b,c,x[i+15],10,-30611744);c=md5ii(c,d,a,b,x[i+6],15,-1560198380);b=md5ii(b,c,d,a,x[i+13],21,1309151649);a=md5ii(a,b,c,d,x[i+4],6,-145523070);d=md5ii(d,a,b,c,x[i+11],10,-1120210379);c=md5ii(c,d,a,b,x[i+2],15,718787259);b=md5ii(b,c,d,a,x[i+9],21,-343485551);a=safeAdd(a,olda);b=safeAdd(b,oldb);c=safeAdd(c,oldc);d=safeAdd(d,oldd);}return [a,b,c,d];}
JS;
    }
}
