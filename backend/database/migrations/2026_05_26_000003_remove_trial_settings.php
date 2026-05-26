<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('system_settings')) {
            DB::connection('central')->table('system_settings')
                ->whereIn('key', [
                    'default_trial_days',
                    'trial_extension_days',
                    'auto_suspend_expired',
                    'notify_trial_expiry',
                    'trial_expiry_days',
                    'require_subscription',
                ])
                ->delete();
        }

        if (Schema::connection('central')->hasTable('tenants')) {
            DB::connection('central')->table('tenants')
                ->where('status', 'approved')
                ->update([
                    'is_active' => true,
                    'trial_ends_at' => null,
                    'subscription_ends_at' => null,
                ]);
        }
    }

    public function down(): void
    {
        // Trial billing is intentionally not restored.
    }
};
