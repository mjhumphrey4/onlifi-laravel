<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('nas') &&
            !Schema::connection('central')->hasColumn('nas', 'provisioning_token')) {
            Schema::connection('central')->table('nas', function (Blueprint $table) {
                $table->string('provisioning_token', 96)->nullable()->unique()->after('router_identifier');
            });

            DB::connection('central')->table('nas')
                ->whereNull('provisioning_token')
                ->orderBy('id')
                ->get(['id'])
                ->each(function ($nas) {
                    DB::connection('central')->table('nas')
                        ->where('id', $nas->id)
                        ->update(['provisioning_token' => Str::random(64)]);
                });
        }
    }

    public function down(): void
    {
        if (Schema::connection('central')->hasTable('nas') &&
            Schema::connection('central')->hasColumn('nas', 'provisioning_token')) {
            Schema::connection('central')->table('nas', function (Blueprint $table) {
                $table->dropUnique(['provisioning_token']);
                $table->dropColumn('provisioning_token');
            });
        }
    }
};
