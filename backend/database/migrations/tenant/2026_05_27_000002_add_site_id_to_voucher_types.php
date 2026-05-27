<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('voucher_types') && !Schema::connection('tenant')->hasColumn('voucher_types', 'site_id')) {
            Schema::connection('tenant')->table('voucher_types', function (Blueprint $table) {
                $table->unsignedBigInteger('site_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('voucher_types') && Schema::connection('tenant')->hasColumn('voucher_types', 'site_id')) {
            Schema::connection('tenant')->table('voucher_types', function (Blueprint $table) {
                $table->dropColumn('site_id');
            });
        }
    }
};
