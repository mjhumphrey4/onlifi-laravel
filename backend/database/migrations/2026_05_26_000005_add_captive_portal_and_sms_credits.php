<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('captive_portal_templates')) {
            Schema::connection('central')->create('captive_portal_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name');
                $table->string('theme')->default('clean');
                $table->json('design')->nullable();
                $table->boolean('is_active')->default(false)->index();
                $table->timestamps();
            });
        }

        if (!Schema::connection('central')->hasTable('sms_wallets')) {
            Schema::connection('central')->create('sms_wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
                $table->unsignedInteger('credits')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::connection('central')->hasTable('sms_credit_transactions')) {
            Schema::connection('central')->create('sms_credit_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('external_ref')->unique();
                $table->string('transaction_ref')->nullable()->index();
                $table->string('network_ref')->nullable()->index();
                $table->string('msisdn');
                $table->decimal('amount', 12, 2);
                $table->unsignedInteger('credits')->default(0);
                $table->enum('status', ['pending', 'success', 'failed'])->default('pending')->index();
                $table->string('status_message')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::connection('central')->hasTable('system_settings')) {
            $settings = [
                [
                    'key' => 'sms_credit_price',
                    'value' => '35',
                    'type' => 'float',
                    'group' => 'sms',
                    'description' => 'Price per SMS credit',
                    'is_public' => false,
                ],
                [
                    'key' => 'default_captive_theme',
                    'value' => 'clean',
                    'type' => 'string',
                    'group' => 'captive',
                    'description' => 'Default captive portal theme',
                    'is_public' => false,
                ],
            ];

            foreach ($settings as $setting) {
                DB::connection('central')->table('system_settings')->updateOrInsert(
                    ['key' => $setting['key']],
                    array_merge($setting, ['created_at' => now(), 'updated_at' => now()])
                );
            }
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sms_credit_transactions');
        Schema::connection('central')->dropIfExists('sms_wallets');
        Schema::connection('central')->dropIfExists('captive_portal_templates');
    }
};
