<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        if (!Schema::connection('central')->hasColumn('sites', 'vpn_public_port')) {
            return;
        }

        DB::connection('central')
            ->table('sites')
            ->where(function ($query) {
                $query->whereNull('vpn_public_port')
                    ->orWhere('vpn_public_port', '!=', 8443);
            })
            ->update(['vpn_public_port' => 8443]);
    }

    public function down(): void
    {
        //
    }
};
