<?php

namespace App\Services;

use App\Models\CaptivePortalTemplate;
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
                'theme' => 'clean',
                'name' => 'Clean Access',
                'description' => 'Simple centered login and mobile money checkout',
                'design' => [
                    'primary_color' => '#2563eb',
                    'background_color' => '#f8fafc',
                    'text_color' => '#0f172a',
                    'headline' => 'Connect to WiFi',
                    'subheadline' => 'Buy a voucher with mobile money or enter your voucher code.',
                    'button_label' => 'Pay with Mobile Money',
                    'logo_url' => '',
                ],
            ],
            [
                'theme' => 'bold',
                'name' => 'Bold Business',
                'description' => 'High contrast layout for shops, malls, and lounges',
                'design' => [
                    'primary_color' => '#16a34a',
                    'background_color' => '#111827',
                    'text_color' => '#f9fafb',
                    'headline' => 'Fast WiFi Access',
                    'subheadline' => 'Choose a package, confirm payment, and browse.',
                    'button_label' => 'Buy Voucher',
                    'logo_url' => '',
                ],
            ],
            [
                'theme' => 'compact',
                'name' => 'Compact',
                'description' => 'Small, quick-loading page for low bandwidth routers',
                'design' => [
                    'primary_color' => '#dc2626',
                    'background_color' => '#ffffff',
                    'text_color' => '#111827',
                    'headline' => 'WiFi Login',
                    'subheadline' => 'Enter voucher or pay by mobile money.',
                    'button_label' => 'Pay Now',
                    'logo_url' => '',
                ],
            ],
        ];
    }

    public function activeTemplateForTenant(Tenant $tenant): array
    {
        $template = CaptivePortalTemplate::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        if ($template) {
            return [
                'id' => $template->id,
                'name' => $template->name,
                'theme' => $template->theme,
                'design' => $template->design ?? [],
            ];
        }

        $theme = (string) SystemSetting::get('default_captive_theme', 'clean');
        $default = collect($this->templates())->firstWhere('theme', $theme) ?: $this->templates()[0];

        return [
            'id' => null,
            'name' => $default['name'],
            'theme' => $default['theme'],
            'design' => $default['design'],
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

        $tenant->configure();
        $site = null;
        if (Schema::connection('central')->hasColumn('nas', 'site_id') && !empty($nas->site_id)) {
            $site = \App\Models\Site::where('tenant_id', $tenant->id)->where('id', $nas->site_id)->first();
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
            'template' => $this->activeTemplateForTenant($tenant),
            'api' => [
                'base_url' => $this->apiBaseUrl(),
                'pay_url' => $this->apiBaseUrl() . '/api/captive/pay',
                'status_url' => $this->apiBaseUrl() . '/api/captive/payment-status',
            ],
            'manual_payment' => [
                'site_name' => $siteName,
                'site_slug' => $this->paymentSiteSlug($siteName),
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
            default => null,
        };
    }

    private function loginHtml(array $config): string
    {
        $template = $this->loadManualLoginTemplate();
        if ($template) {
            return $this->renderManualLoginTemplate($template, $config);
        }

        return $this->defaultManualLoginHtml($config);
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
            base_path('OLD-Flow/login.html'),
            base_path('EgoSMS Flow/login.html'),
            resource_path('hotspot/login-manual.html'),
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
        $replacements = [
            '{{SITE_NAME}}' => $config['manual_payment']['site_name'],
            '{{SITE_SLUG}}' => $config['manual_payment']['site_slug'],
            '{{SITE_NAME_FIELD}}' => $config['manual_payment']['site_name'],
            '{{PAYMENT_INITIATE_URL}}' => $config['manual_payment']['initiate_url'],
            '{{MANUAL_PAYMENT_URL}}' => $config['manual_payment']['initiate_url'],
            '{{PAYMENT_CHECK_STATUS_URL}}' => $config['manual_payment']['check_status_url'],
            '{{VOUCHER_LOOKUP_URL}}' => $config['manual_payment']['voucher_lookup_url'],
            '{{API_BASE_URL}}' => $config['api']['base_url'],
            '{{ROUTER_TOKEN}}' => $config['router']['token'],
        ];

        $html = str_replace(array_keys($replacements), array_map('htmlspecialchars', array_values($replacements)), $template);

        $html = str_replace(
            ['SITE_NAME_PLACEHOLDER', 'PAYMENT_ENDPOINT_PLACEHOLDER'],
            [$config['manual_payment']['site_name'], $config['manual_payment']['initiate_url']],
            $html
        );

        $legacyReplacements = [
            "const CURRENT_ORIGIN_SITE = 'STK WIFI';" => 'const CURRENT_ORIGIN_SITE = ' . json_encode($config['manual_payment']['site_name']) . ';',
            'http://pay.onlustech.com/yo/initiate.php' => $config['manual_payment']['initiate_url'],
            'http://pay.onlustech.com/yo/check_status.php' => $config['manual_payment']['check_status_url'],
            'http://pay.onlustech.com/yo/look/voucher-lookup.php' => $config['manual_payment']['voucher_lookup_url'],
        ];

        $html = str_replace(array_keys($legacyReplacements), array_values($legacyReplacements), $html);

        return str_replace(
            ['STK WIFI POINT', 'STK WIFI'],
            [htmlspecialchars($config['manual_payment']['site_name'], ENT_QUOTES), htmlspecialchars($config['manual_payment']['site_name'], ENT_QUOTES)],
            $html
        );
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

    private function simpleHtml(array $config, string $title, string $message): string
    {
        $tenantName = htmlspecialchars($config['tenant']['name'], ENT_QUOTES);
        $title = htmlspecialchars($title, ENT_QUOTES);
        $message = htmlspecialchars($message, ENT_QUOTES);

        return "<!doctype html><html><head><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$title}</title><style>body{font-family:Arial,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f8fafc;color:#0f172a}.box{max-width:420px;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:white}</style></head><body><div class=\"box\"><small>{$tenantName}</small><h1>{$title}</h1><p>{$message}</p></div></body></html>";
    }
}
