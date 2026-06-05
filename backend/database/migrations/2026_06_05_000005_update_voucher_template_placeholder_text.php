<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('voucher_templates')) {
            return;
        }

        DB::connection('central')
            ->table('voucher_templates')
            ->where('header_text', 'STK WIFI POINT')
            ->update([
                'header_text' => 'WIFI NAME',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        //
    }
};
