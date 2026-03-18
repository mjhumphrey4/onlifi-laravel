<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Fees Table
 * 
 * Tracks all platform fees collected from transactions.
 * Admin sets fee percentages, and for every transaction:
 * - Collection fee: deducted when customer pays
 * - The tenant receives: amount - platform_fee
 * 
 * All transactions go through OnLiFi's YoAPI account,
 * fees are automatically calculated and recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('platform_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('transaction_ref')->index();  // Reference to tenant's transaction
            $table->decimal('gross_amount', 12, 2);      // Total amount paid by customer
            $table->decimal('platform_fee', 12, 2);      // Fee taken by platform
            $table->decimal('net_amount', 12, 2);        // Amount credited to tenant
            $table->decimal('fee_percentage', 5, 2);     // Fee % at time of transaction
            $table->enum('status', ['pending', 'collected', 'disbursed', 'failed'])->default('pending');
            $table->string('yo_transaction_ref')->nullable();  // YoAPI transaction reference
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'status']);
            $table->index('created_at');
        });

        // Platform revenue summary (for admin dashboard)
        Schema::connection('central')->create('platform_revenue', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->decimal('total_collections', 14, 2)->default(0);  // Total collected from customers
            $table->decimal('total_fees', 14, 2)->default(0);         // Total platform fees
            $table->decimal('total_disbursed', 14, 2)->default(0);    // Total paid to tenants
            $table->integer('transaction_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('platform_revenue');
        Schema::connection('central')->dropIfExists('platform_fees');
    }
};
