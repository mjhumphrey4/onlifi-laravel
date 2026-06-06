<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_payment_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('provider_type')->default('collection')->index();
            $table->string('status')->default('inactive')->index();
            $table->unsignedInteger('priority')->default(100);
            $table->string('base_url')->nullable();
            $table->string('callback_url')->nullable();
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('manual_payment_providers')->insert([
            [
                'name' => 'Yo Uganda Collections',
                'code' => 'yo_uganda',
                'provider_type' => 'collection',
                'status' => 'active',
                'priority' => 10,
                'base_url' => 'https://www.yo.co.ug/ybs/task.php',
                'callback_url' => '/ipn.php',
                'settings' => json_encode([
                    'mode' => 'production',
                    'currency' => 'UGX',
                    'minimum_amount' => 200,
                    'failure_callback' => '/failure.php',
                ]),
                'notes' => 'Primary provider used by the original manual payment PHP flow.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'IOTEC Fallback Collections',
                'code' => 'iotec',
                'provider_type' => 'fallback',
                'status' => 'inactive',
                'priority' => 20,
                'base_url' => 'https://pay.iotec.io/api',
                'callback_url' => '/IOTEC/callback.php',
                'settings' => json_encode([
                    'auth_url' => 'https://id.iotec.io/connect/token',
                    'currency' => 'UGX',
                    'category' => 'MobileMoney',
                ]),
                'notes' => 'Fallback provider from payment_fallback_helper.php. Credentials are intentionally blank here.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payment_providers');
    }
};
