<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iotec_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->text('encrypted_value')->nullable();
            $table->string('type')->default('string');
            $table->string('group')->default('general')->index();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('iotec_settings')->insert([
            [
                'key' => 'iotec_public_base_url',
                'value' => 'https://bitetechsystems.com/yo/IOTEC/',
                'type' => 'string',
                'group' => 'runtime',
                'label' => 'IOTEC public URL',
                'description' => 'Base URL for the deployed IOTEC PHP payment endpoints.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'polling_enabled',
                'value' => 'true',
                'type' => 'boolean',
                'group' => 'polling',
                'label' => 'Polling enabled',
                'description' => 'Shows whether pending transaction polling should be active in production.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'polling_window_hours',
                'value' => '24',
                'type' => 'integer',
                'group' => 'polling',
                'label' => 'Polling window hours',
                'description' => 'Pending transactions older than this are ignored by the polling worker.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'polling_grace_seconds',
                'value' => '30',
                'type' => 'integer',
                'group' => 'polling',
                'label' => 'Polling grace seconds',
                'description' => 'How long to wait after initiation before polling IOTEC status.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'telecom_fee_percent',
                'value' => '4',
                'type' => 'float',
                'group' => 'fees',
                'label' => 'Telecom fee percent',
                'description' => 'IOTEC STK telecom fee used by the existing PHP status/callback logic.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'daily_first_success_platform_fee',
                'value' => '2000',
                'type' => 'float',
                'group' => 'fees',
                'label' => 'Daily first success platform fee',
                'description' => 'Platform fee applied to the first successful transaction per site per day.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_db_host',
                'value' => 'localhost',
                'type' => 'string',
                'group' => 'legacy_database',
                'label' => 'IOTEC DB host',
                'description' => 'Host for the IOTEC transaction database used by the PHP provider.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_db_port',
                'value' => '3306',
                'type' => 'integer',
                'group' => 'legacy_database',
                'label' => 'IOTEC DB port',
                'description' => 'Database port for the IOTEC transaction database.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_db_name',
                'value' => 'payment_mikrotik',
                'type' => 'string',
                'group' => 'legacy_database',
                'label' => 'IOTEC DB name',
                'description' => 'Database containing the IOTEC transactions table.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_db_user',
                'value' => 'yo',
                'type' => 'string',
                'group' => 'legacy_database',
                'label' => 'IOTEC DB username',
                'description' => 'Database user for IOTEC transaction management.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_db_password',
                'value' => null,
                'type' => 'string',
                'group' => 'legacy_database',
                'label' => 'IOTEC DB password',
                'description' => 'Sensitive database password for IOTEC transaction management.',
                'is_sensitive' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_transactions_table',
                'value' => 'transactions',
                'type' => 'string',
                'group' => 'legacy_database',
                'label' => 'Transactions table',
                'description' => 'Table used by IOTEC initiate, callback, status, and polling scripts.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'sms_functionality_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'group' => 'runtime',
                'label' => 'SMS functionality enabled',
                'description' => 'Disabled until the new SMS provider and usage style are supplied.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('iotec_settings');
    }
};
