<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('voucher_sales_points')) {
            if (!Schema::hasColumn('voucher_sales_points', 'tenant_id')) {
                Schema::table('voucher_sales_points', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->after('id')->index();
                });
            }
            return;
        }
        
        Schema::create('voucher_sales_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 100);
            $table->string('location')->nullable();
            $table->string('contact_person', 100)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_sales_points');
    }
};
