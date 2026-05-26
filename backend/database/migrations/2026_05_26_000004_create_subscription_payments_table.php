<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('subscription_payments')) {
            return;
        }

        Schema::connection('central')->create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('external_ref')->unique();
            $table->string('transaction_ref')->nullable()->index();
            $table->string('network_ref')->nullable()->index();
            $table->string('msisdn');
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('months')->default(1);
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending')->index();
            $table->string('status_message')->nullable();
            $table->string('narrative')->nullable();
            $table->timestamp('subscription_ends_at_before')->nullable();
            $table->timestamp('subscription_ends_at_after')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('subscription_payments');
    }
};
