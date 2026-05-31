<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class SystemSettingController extends Controller
{
    public function index(Request $request)
    {
        $query = SystemSetting::query();

        if ($request->has('group')) {
            $query->where('group', $request->group);
        }

        $settings = $query->orderBy('group')->orderBy('key')->get();

        return response()->json($settings);
    }

    public function groups()
    {
        $groups = SystemSetting::select('group')
            ->distinct()
            ->orderBy('group')
            ->pluck('group');

        return response()->json($groups);
    }

    public function byGroup(string $group)
    {
        $settings = SystemSetting::getByGroup($group);

        return response()->json($settings);
    }

    public function publicSettings()
    {
        $settings = SystemSetting::getPublic();

        return response()->json($settings);
    }

    public function show(string $key)
    {
        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            return response()->json([
                'error' => 'Setting not found',
            ], 404);
        }

        return response()->json($setting);
    }

    public function update(Request $request, string $key)
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required',
            'type' => 'in:string,integer,float,boolean,array,json',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            return response()->json([
                'error' => 'Setting not found',
            ], 404);
        }

        $value = $request->value;
        if (in_array($request->type ?? $setting->type, ['array', 'json']) && is_array($value)) {
            $value = json_encode($value);
        }

        $setting->update([
            'value' => $value,
            'type' => $request->type ?? $setting->type,
            'description' => $request->description ?? $setting->description,
            'is_public' => $request->is_public ?? $setting->is_public,
        ]);

        return response()->json([
            'message' => 'Setting updated successfully',
            'setting' => $setting->fresh(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string|unique:system_settings,key',
            'value' => 'required',
            'type' => 'required|in:string,integer,float,boolean,array,json',
            'group' => 'required|string',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $value = $request->value;
        if (in_array($request->type, ['array', 'json']) && is_array($value)) {
            $value = json_encode($value);
        }

        $setting = SystemSetting::create([
            'key' => $request->key,
            'value' => $value,
            'type' => $request->type,
            'group' => $request->group,
            'description' => $request->description,
            'is_public' => $request->is_public ?? false,
        ]);

        return response()->json([
            'message' => 'Setting created successfully',
            'setting' => $setting,
        ], 201);
    }

    public function destroy(string $key)
    {
        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            return response()->json([
                'error' => 'Setting not found',
            ], 404);
        }

        $setting->delete();

        return response()->json([
            'message' => 'Setting deleted successfully',
        ]);
    }

    public function bulkUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'present|nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::connection('central')->transaction(function () use ($request) {
            foreach ($request->settings as $settingData) {
                $key = $settingData['key'];
                $setting = SystemSetting::where('key', $key)->first();
                $defaults = $this->settingDefaults($key);

                $value = $settingData['value'];
                $type = $setting?->type ?: $defaults['type'];
                if (in_array($type, ['array', 'json']) && is_array($value)) {
                    $value = json_encode($value);
                }

                SystemSetting::updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => $value,
                        'type' => $type,
                        'group' => $setting?->group ?: $defaults['group'],
                        'description' => $setting?->description ?: $defaults['description'],
                        'is_public' => $setting?->is_public ?? $defaults['is_public'],
                    ]
                );
            }

            $sharedSecret = collect($request->settings)->firstWhere('key', 'radius_shared_secret')['value'] ?? null;
            if ($sharedSecret !== null && $sharedSecret !== '' && Schema::connection('central')->hasTable('nas')) {
                DB::connection('central')->table('nas')->update([
                    'secret' => $sharedSecret,
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json([
            'message' => 'Settings updated successfully',
        ]);
    }

    private function settingDefaults(string $key): array
    {
        $known = [
            'app_name' => ['string', 'general', 'The name of your application', true],
            'app_url' => ['string', 'general', 'The base URL of your application', true],
            'support_email' => ['string', 'general', 'Email for support inquiries', false],
            'platform_collection_fee_percent' => ['float', 'payment', 'Percentage fee on incoming payments', false],
            'platform_minimum_disbursement' => ['float', 'payment', 'Minimum tenant payout amount', false],
            'default_trial_days' => ['integer', 'payment', 'Trial period granted when a tenant is approved', false],
            'tenant_monthly_subscription_amount' => ['float', 'payment', 'Default monthly platform charge for tenants', false],
            'tenant_subscription_currency' => ['string', 'payment', 'Currency displayed on subscription invoices and prompts', false],
            'subscription_renewal_months' => ['integer', 'payment', 'Default months purchased when a tenant renews', false],
            'require_subscription' => ['boolean', 'payment', 'Require tenants to renew after trial expiry', false],
            'dashboard_lock_on_expired_subscription' => ['boolean', 'payment', 'Keep services active but block dashboard access when billing expires', false],
            'payment_gateway' => ['string', 'payment', 'Active payment gateway', false],
            'radius_server_ip' => ['string', 'radius', 'FreeRADIUS server address used in generated router scripts', false],
            'radius_auth_port' => ['integer', 'radius', 'RADIUS authentication UDP port', false],
            'radius_acct_port' => ['integer', 'radius', 'RADIUS accounting UDP port', false],
            'radius_shared_secret' => ['string', 'radius', 'Shared secret used by dynamic MikroTik routers', false],
            'smtp_host' => ['string', 'email', 'SMTP server hostname', false],
            'smtp_port' => ['integer', 'email', 'SMTP server port', false],
            'smtp_username' => ['string', 'email', 'SMTP authentication username', false],
            'smtp_password' => ['string', 'email', 'SMTP authentication password', false],
            'smtp_encryption' => ['string', 'email', 'SMTP encryption: tls, ssl, or empty', false],
            'smtp_from_address' => ['string', 'email', 'Default sender email address', false],
            'smtp_from_name' => ['string', 'email', 'Default sender name', false],
            'session_lifetime' => ['integer', 'security', 'How long sessions remain active', false],
            'password_min_length' => ['integer', 'security', 'Minimum password length requirement', false],
            'require_2fa' => ['boolean', 'security', 'Require two-factor authentication', false],
            'notify_new_signup' => ['boolean', 'notifications', 'Email admin on new signups', false],
            'notify_signup_email' => ['boolean', 'notifications', 'Email tenant after signup', false],
            'notify_activation_email' => ['boolean', 'notifications', 'Email tenant after approval', false],
            'notify_password_reset_email' => ['boolean', 'notifications', 'Email tenant after password reset', false],
            'notify_announcement_email' => ['boolean', 'notifications', 'Allow announcements to be emailed', false],
        ];

        [$type, $group, $description, $isPublic] = $known[$key] ?? [
            'string',
            str_contains($key, '_') ? str($key)->before('_')->toString() : 'general',
            null,
            false,
        ];

        return compact('type', 'group', 'description', 'isPublic');
    }
}
