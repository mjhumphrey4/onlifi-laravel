<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iotec_api_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status')->default('draft')->index();
            $table->string('environment')->default('production');
            $table->string('auth_url');
            $table->string('api_base_url');
            $table->string('wallet_id')->nullable();
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->string('callback_url')->nullable();
            $table->string('default_currency')->default('UGX');
            $table->string('default_category')->default('MobileMoney');
            $table->json('settings')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::table('iotec_api_profiles')->insert([
            'name' => 'IOTEC Collections',
            'code' => 'iotec_collections',
            'status' => 'active',
            'environment' => 'production',
            'auth_url' => 'https://id.iotec.io/connect/token',
            'api_base_url' => 'https://pay.iotec.io/api',
            'wallet_id' => '',
            'client_id' => '',
            'callback_url' => '/callback.php',
            'default_currency' => 'UGX',
            'default_category' => 'MobileMoney',
            'settings' => json_encode([
                'collect_endpoint' => '/collections/collect',
                'status_endpoint_template' => '/collections/status/{transactionId}',
                'token_grant_type' => 'client_credentials',
            ]),
            'notes' => 'Credentials are intentionally blank. Store them here instead of hardcoding future releases.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('iotec_api_profiles');
    }
};
