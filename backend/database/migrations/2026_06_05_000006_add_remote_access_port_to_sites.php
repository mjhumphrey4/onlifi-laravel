<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        if (!Schema::connection('central')->hasColumn('sites', 'remote_access_port')) {
            Schema::connection('central')->table('sites', function (Blueprint $table) {
                $table->unsignedInteger('remote_access_port')->nullable()->after('router_api_port');
                $table->index('remote_access_port');
            });
        }

        $usedPorts = DB::connection('central')
            ->table('sites')
            ->whereNotNull('remote_access_port')
            ->pluck('remote_access_port')
            ->map(fn ($port) => (int) $port)
            ->all();

        $usedPorts = array_fill_keys($usedPorts, true);
        $nextPort = 32000;

        DB::connection('central')
            ->table('sites')
            ->whereNull('remote_access_port')
            ->orderBy('id')
            ->get(['id'])
            ->each(function ($site) use (&$usedPorts, &$nextPort) {
                while (isset($usedPorts[$nextPort]) && $nextPort <= 65535) {
                    $nextPort++;
                }

                if ($nextPort > 65535) {
                    throw new RuntimeException('No available remote access ports remain.');
                }

                DB::connection('central')
                    ->table('sites')
                    ->where('id', $site->id)
                    ->update(['remote_access_port' => $nextPort]);

                $usedPorts[$nextPort] = true;
                $nextPort++;
            });
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('sites') || !Schema::connection('central')->hasColumn('sites', 'remote_access_port')) {
            return;
        }

        Schema::connection('central')->table('sites', function (Blueprint $table) {
            $table->dropIndex(['remote_access_port']);
            $table->dropColumn('remote_access_port');
        });
    }
};
