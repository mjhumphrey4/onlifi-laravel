<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('system_settings')) {
            $settings = [
                [
                    'key' => 'default_trial_days',
                    'value' => '15',
                    'type' => 'integer',
                    'group' => 'billing',
                    'description' => 'Default trial period for newly approved tenants',
                    'is_public' => false,
                ],
                [
                    'key' => 'tenant_monthly_subscription_amount',
                    'value' => '50000',
                    'type' => 'float',
                    'group' => 'billing',
                    'description' => 'Default monthly subscription charge for tenants',
                    'is_public' => false,
                ],
                [
                    'key' => 'tenant_subscription_currency',
                    'value' => 'UGX',
                    'type' => 'string',
                    'group' => 'billing',
                    'description' => 'Currency used for tenant subscription billing',
                    'is_public' => false,
                ],
                [
                    'key' => 'subscription_renewal_months',
                    'value' => '1',
                    'type' => 'integer',
                    'group' => 'billing',
                    'description' => 'Default number of months purchased per tenant renewal',
                    'is_public' => false,
                ],
                [
                    'key' => 'require_subscription',
                    'value' => '1',
                    'type' => 'boolean',
                    'group' => 'billing',
                    'description' => 'Require tenant dashboard billing to be current after trial',
                    'is_public' => false,
                ],
                [
                    'key' => 'dashboard_lock_on_expired_subscription',
                    'value' => '1',
                    'type' => 'boolean',
                    'group' => 'billing',
                    'description' => 'Lock tenant dashboard when subscription billing expires',
                    'is_public' => false,
                ],
                [
                    'key' => 'notify_trial_expiry',
                    'value' => '1',
                    'type' => 'boolean',
                    'group' => 'billing',
                    'description' => 'Notify tenants before trial expiry',
                    'is_public' => false,
                ],
                [
                    'key' => 'trial_expiry_days',
                    'value' => '3',
                    'type' => 'integer',
                    'group' => 'billing',
                    'description' => 'Days before trial expiry to start showing warnings',
                    'is_public' => false,
                ],
            ];

            foreach ($settings as $setting) {
                DB::connection('central')->table('system_settings')->updateOrInsert(
                    ['key' => $setting['key']],
                    array_merge($setting, [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                );
            }
        }

        if (Schema::connection('central')->hasTable('tenants')) {
            DB::connection('central')->table('tenants')
                ->where('status', 'approved')
                ->whereNull('trial_ends_at')
                ->whereNull('subscription_ends_at')
                ->update([
                    'is_active' => true,
                    'trial_ends_at' => now()->addDays(15),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::connection('central')->hasTable('system_settings')) {
            DB::connection('central')->table('system_settings')
                ->whereIn('key', [
                    'default_trial_days',
                    'tenant_monthly_subscription_amount',
                    'tenant_subscription_currency',
                    'subscription_renewal_months',
                    'require_subscription',
                    'dashboard_lock_on_expired_subscription',
                    'notify_trial_expiry',
                    'trial_expiry_days',
                ])
                ->delete();
        }
    }
};
