<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_callback_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('event')->index();
            $table->string('method')->default('POST');
            $table->string('url');
            $table->text('headers')->nullable();
            $table->text('signing_secret')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('last_status')->nullable();
            $table->timestamp('last_called_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('manual_callback_endpoints')->insert([
            [
                'name' => 'Payment Success IPN',
                'event' => 'payment.success',
                'method' => 'POST',
                'url' => '/ipn.php',
                'notes' => 'Provider callback for completed Yo Uganda collections.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Payment Failure Callback',
                'event' => 'payment.failed',
                'method' => 'POST',
                'url' => '/failure.php',
                'notes' => 'Provider callback for failed or declined collection attempts.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'IOTEC Fallback Callback',
                'event' => 'fallback.updated',
                'method' => 'POST',
                'url' => '/IOTEC/callback.php',
                'notes' => 'Fallback payment status callback.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_callback_endpoints');
    }
};
