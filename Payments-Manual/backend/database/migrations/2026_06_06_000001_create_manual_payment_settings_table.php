<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_payment_settings', function (Blueprint $table) {
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
        DB::table('manual_payment_settings')->insert([
            [
                'key' => 'manual_public_base_url',
                'value' => 'https://pay.onlifi.net/ranken/',
                'type' => 'string',
                'group' => 'runtime',
                'label' => 'Manual payment public URL',
                'description' => 'Base URL used when generating provider callback URLs.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_db_host',
                'value' => '10.200.1.254',
                'type' => 'string',
                'group' => 'legacy_database',
                'label' => 'Legacy DB host',
                'description' => 'Host for the original manual payment transaction database.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_db_port',
                'value' => '3306',
                'type' => 'integer',
                'group' => 'legacy_database',
                'label' => 'Legacy DB port',
                'description' => 'Port for the original manual payment transaction database.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_db_name',
                'value' => 'onlifi_1_1_stk',
                'type' => 'string',
                'group' => 'legacy_database',
                'label' => 'Legacy DB name',
                'description' => 'Database that contains the legacy transactions table.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_db_user',
                'value' => 'yo',
                'type' => 'string',
                'group' => 'legacy_database',
                'label' => 'Legacy DB username',
                'description' => 'Database user for read/write dashboard management access.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_db_password',
                'value' => null,
                'type' => 'string',
                'group' => 'legacy_database',
                'label' => 'Legacy DB password',
                'description' => 'Sensitive password for the legacy transaction database.',
                'is_sensitive' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'legacy_transactions_table',
                'value' => 'transactions',
                'type' => 'string',
                'group' => 'legacy_database',
                'label' => 'Legacy transactions table',
                'description' => 'Table used by the original manual PHP payment flow.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'sms_functionality_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'group' => 'runtime',
                'label' => 'SMS functionality enabled',
                'description' => 'Kept off until the new SMS provider and usage style are supplied.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payment_settings');
    }
};
