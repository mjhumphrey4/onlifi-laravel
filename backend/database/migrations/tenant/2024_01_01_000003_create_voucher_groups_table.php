<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('voucher_groups')) {
            if (!Schema::hasColumn('voucher_groups', 'tenant_id')) {
                Schema::table('voucher_groups', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->after('id')->index();
                });
            }
            return;
        }
        
        Schema::create('voucher_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('group_name', 100);
            $table->text('description')->nullable();
            $table->string('profile_name', 64);
            $table->integer('validity_hours');
            $table->integer('data_limit_mb')->nullable();
            $table->integer('speed_limit_kbps')->nullable();
            $table->decimal('price', 10, 2);
            $table->foreignId('sales_point_id')->nullable()->constrained('voucher_sales_points')->nullOnDelete();
            $table->string('created_by', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_groups');
    }
};
