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

        DB::connection('central')
            ->table('system_settings')
            ->where('key', 'manual_payment_base_url')
            ->where(function ($query) {
                $query->where('value', 'like', '%pay.onlustech.com%')
                    ->orWhere('value', 'like', '%bitetechsystems.com%')
                    ->orWhereNull('value')
                    ->orWhere('value', '');
            })
            ->update([
                'value' => 'https://pay.onlifi.net',
                'type' => 'string',
                'group' => 'domains',
                'description' => 'Base URL for manually managed captive payment folders',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Do not restore legacy payment domains.
    }
};
