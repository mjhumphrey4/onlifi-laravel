<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_code', 64)->unique();
            $table->string('password', 64);
            $table->foreignId('group_id')->constrained('voucher_groups')->cascadeOnDelete();
            $table->string('profile_name', 64);
            $table->integer('validity_hours');
            $table->integer('data_limit_mb')->nullable();
            $table->integer('speed_limit_kbps')->nullable();
            $table->decimal('price', 10, 2);
            $table->foreignId('sales_point_id')->nullable()->constrained('voucher_sales_points')->nullOnDelete();
            $table->enum('status', ['unused', 'used', 'expired', 'disabled'])->default('unused')->index();
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('used_by_mac', 17)->nullable();
            $table->string('used_by_ip', 15)->nullable();
            $table->decimal('total_data_used_mb', 10, 2)->default(0);
            $table->integer('total_session_time_minutes')->default(0);
            $table->timestamps();
            
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
