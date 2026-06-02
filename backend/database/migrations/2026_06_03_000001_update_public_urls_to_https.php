<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('system_settings')) {
            return;
        }

        $replacements = [
            'api_base_url' => 'https://api.onlifi.net',
            'dashboard_url' => 'https://onlifi.net',
            'manual_payment_base_url' => 'https://pay.onlifi.net',
            'remote_access_mobile_app_url' => 'https://onlifi.net/downloads/onlifi-mobile.apk',
            'remote_access_web_login_url' => 'https://vpn.onlifi.net',
        ];

        foreach ($replacements as $key => $value) {
            DB::connection('central')
                ->table('system_settings')
                ->where('key', $key)
                ->where(function ($query) {
                    $query->where('value', 'like', 'http://onlifi.net%')
                        ->orWhere('value', 'like', 'http://api.onlifi.net%')
                        ->orWhere('value', 'like', 'http://pay.onlifi.net%')
                        ->orWhere('value', 'like', 'http://vpn.onlifi.net%');
                })
                ->update(['value' => $value]);
        }
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('system_settings')) {
            return;
        }

        $replacements = [
            'api_base_url' => 'http://api.onlifi.net',
            'dashboard_url' => 'http://onlifi.net',
            'manual_payment_base_url' => 'http://pay.onlifi.net',
            'remote_access_mobile_app_url' => 'http://onlifi.net/downloads/onlifi-mobile.apk',
            'remote_access_web_login_url' => 'http://vpn.onlifi.net',
        ];

        foreach ($replacements as $key => $value) {
            DB::connection('central')
                ->table('system_settings')
                ->where('key', $key)
                ->update(['value' => $value]);
        }
    }
};
