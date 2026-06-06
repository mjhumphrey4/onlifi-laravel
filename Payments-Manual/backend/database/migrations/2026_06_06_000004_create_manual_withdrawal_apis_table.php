<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_withdrawal_apis', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider_code')->unique();
            $table->string('status')->default('draft')->index();
            $table->string('base_url')->nullable();
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->decimal('daily_limit', 14, 2)->default(0);
            $table->decimal('minimum_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_withdrawal_apis');
    }
};
