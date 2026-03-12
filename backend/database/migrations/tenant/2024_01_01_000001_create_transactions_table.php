<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('external_ref', 100)->unique();
            $table->string('transaction_ref', 100)->nullable()->index();
            $table->string('msisdn', 20);
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending')->index();
            $table->string('status_message')->nullable();
            $table->string('origin_site', 100)->nullable()->index();
            $table->string('client_mac', 17)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('voucher_type', 100)->nullable();
            $table->string('voucher_code', 64)->nullable()->index();
            $table->text('origin_url')->nullable();
            $table->string('network_ref', 100)->nullable();
            $table->timestamps();
            
            $table->index(['status', 'created_at']);
            $table->index(['msisdn', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
