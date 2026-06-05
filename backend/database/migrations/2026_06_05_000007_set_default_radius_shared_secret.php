<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_SECRET = 'Onlifi26A';

    private const LEGACY_SECRETS = [
        '',
        'onlifi_radius_secret_change_me',
        'Onlifi@@rad_Secret$Xb@@26',
    ];

    public function up(): void
    {
        if (Schema::connection('central')->hasTable('system_settings')) {
            $existing = DB::connection('central')
                ->table('system_settings')
                ->where('key', 'radius_shared_secret')
                ->value('value');

            if ($existing === null) {
                DB::connection('central')->table('system_settings')->insert([
                    'key' => 'radius_shared_secret',
                    'value' => self::DEFAULT_SECRET,
                    'type' => 'string',
                    'group' => 'radius',
                    'description' => 'Shared secret used by dynamic MikroTik routers',
                    'is_public' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif (in_array((string) $existing, self::LEGACY_SECRETS, true)) {
                DB::connection('central')
                    ->table('system_settings')
                    ->where('key', 'radius_shared_secret')
                    ->update([
                        'value' => self::DEFAULT_SECRET,
                        'type' => 'string',
                        'group' => 'radius',
                        'description' => 'Shared secret used by dynamic MikroTik routers',
                        'updated_at' => now(),
                    ]);
            }
        }

        if (Schema::connection('central')->hasTable('nas') && Schema::connection('central')->hasColumn('nas', 'secret')) {
            DB::connection('central')
                ->table('nas')
                ->whereIn('secret', self::LEGACY_SECRETS)
                ->update(['secret' => self::DEFAULT_SECRET]);
        }
    }

    public function down(): void
    {
        // Do not restore old shared secrets.
    }
};
