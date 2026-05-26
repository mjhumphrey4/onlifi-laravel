<?php

namespace App\Services;

use App\Models\CaptivePortalTemplate;
use App\Models\SystemSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

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
        $packages = DB::connection('tenant')->table('voucher_groups')
            ->select('group_name as name', 'price', 'validity_hours', 'data_limit_mb', 'speed_limit_kbps')
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
                'name' => $nas->shortname,
                'identifier' => $nas->router_identifier,
                'token' => $token,
            ],
            'template' => $this->activeTemplateForTenant($tenant),
            'api' => [
                'base_url' => rtrim(config('app.url'), '/'),
                'pay_url' => rtrim(config('app.url'), '/') . '/api/captive/pay',
                'status_url' => rtrim(config('app.url'), '/') . '/api/captive/payment-status',
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
        $design = array_merge([
            'primary_color' => '#2563eb',
            'background_color' => '#f8fafc',
            'text_color' => '#0f172a',
            'headline' => 'Connect to WiFi',
            'subheadline' => 'Buy a voucher with mobile money or enter your voucher code.',
            'button_label' => 'Pay with Mobile Money',
        ], $config['template']['design'] ?? []);

        $jsonConfig = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $primary = htmlspecialchars($design['primary_color'], ENT_QUOTES);
        $background = htmlspecialchars($design['background_color'], ENT_QUOTES);
        $text = htmlspecialchars($design['text_color'], ENT_QUOTES);
        $headline = htmlspecialchars($design['headline'], ENT_QUOTES);
        $subheadline = htmlspecialchars($design['subheadline'], ENT_QUOTES);
        $buttonLabel = htmlspecialchars($design['button_label'], ENT_QUOTES);

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
    .wrap{width:100%;max-width:440px;background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:12px;padding:22px;box-shadow:0 20px 50px rgba(15,23,42,.14)}
    h1{margin:0 0 6px;font-size:26px}.muted{color:#64748b;font-size:14px;line-height:1.45}.tabs{display:flex;gap:8px;margin:18px 0}
    button,.tab{border:0;border-radius:8px;padding:11px 13px;font-weight:700;cursor:pointer}.tab{flex:1;background:#e2e8f0}.tab.active,button.primary{background:var(--primary);color:#fff}
    input,select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;margin:8px 0 12px;font-size:15px}.row{display:grid;gap:10px}.hide{display:none}.msg{font-size:14px;margin-top:10px}.ok{color:#047857}.err{color:#b91c1c}
    .package{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px;border:1px solid #e2e8f0;border-radius:8px;margin:8px 0;background:#f8fafc}
    .tenant{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:4px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="tenant" id="tenantName"></div>
    <h1>{$headline}</h1>
    <p class="muted">{$subheadline}</p>
    <div class="tabs"><button class="tab active" onclick="showTab('pay')">Mobile Money</button><button class="tab" onclick="showTab('voucher')">Voucher</button></div>
    <section id="payTab">
      <div id="packages"></div>
      <input id="phone" inputmode="tel" placeholder="Mobile money number e.g. 2567XXXXXXXX">
      <button class="primary" style="width:100%" onclick="startPayment()">{$buttonLabel}</button>
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
    async function startPayment(){const msg=document.getElementById('payMsg');msg.className='msg';msg.textContent='Starting payment...';try{const r=await fetch(ONLIFI.api.pay_url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({token:ONLIFI.router.token,msisdn:document.getElementById('phone').value,amount:selectedPackage.price,voucher_type:selectedPackage.name,client_mac:'\$(mac)',origin_url:'\$(link-orig)'})});const data=await r.json();if(!r.ok)throw new Error(data.message||data.errorMessage||'Payment failed');pollRef=data.externalReference||data.transactionReference;msg.textContent='Confirm the prompt on your phone...';setTimeout(checkPayment,5000);}catch(e){msg.className='msg err';msg.textContent=e.message;}}
    async function checkPayment(){if(!pollRef)return;const msg=document.getElementById('payMsg');try{const r=await fetch(ONLIFI.api.status_url+'?ref='+encodeURIComponent(pollRef));const data=await r.json();if(data.transactionStatus===1){msg.className='msg ok';msg.textContent='Payment confirmed. Connecting...';document.getElementById('voucher').value=data.voucherCode;document.getElementById('password').value=data.password||data.voucherCode;document.login.submit();return;}if(data.transactionStatus===-1){msg.className='msg err';msg.textContent=data.errorMessage||'Payment failed';return;}msg.textContent='Waiting for confirmation...';setTimeout(checkPayment,5000);}catch(e){msg.textContent='Waiting for confirmation...';setTimeout(checkPayment,5000);}}
    renderPackages();
  </script>
</body>
</html>
HTML;
    }

    private function simpleHtml(array $config, string $title, string $message): string
    {
        $tenantName = htmlspecialchars($config['tenant']['name'], ENT_QUOTES);
        $title = htmlspecialchars($title, ENT_QUOTES);
        $message = htmlspecialchars($message, ENT_QUOTES);

        return "<!doctype html><html><head><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$title}</title><style>body{font-family:Arial,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f8fafc;color:#0f172a}.box{max-width:420px;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:white}</style></head><body><div class=\"box\"><small>{$tenantName}</small><h1>{$title}</h1><p>{$message}</p></div></body></html>";
    }
}
