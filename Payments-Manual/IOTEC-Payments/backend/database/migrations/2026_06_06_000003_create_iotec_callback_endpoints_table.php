<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iotec_callback_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('event')->index();
            $table->string('method')->default('POST');
            $table->string('url');
            $table->json('expected_fields')->nullable();
            $table->text('headers')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('last_status')->nullable();
            $table->timestamp('last_called_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::table('iotec_callback_endpoints')->insert([
            'name' => 'IOTEC Payment Callback',
            'event' => 'collection.status.changed',
            'method' => 'POST',
            'url' => '/callback.php',
            'expected_fields' => json_encode([
                'id',
                'externalId',
                'status',
                'statusCode',
                'statusMessage',
                'amount',
                'payer',
                'payerName',
                'vendor',
                'vendorTransactionId',
                'transactionCharge',
                'vendorCharge',
                'totalTransactionCharge',
                'createdAt',
                'processedAt',
            ]),
            'notes' => 'Payload shape documented in the existing IOTEC callback.php handler.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('iotec_callback_endpoints');
    }
};
