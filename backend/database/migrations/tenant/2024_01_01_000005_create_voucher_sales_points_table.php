<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_sales_points', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
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
