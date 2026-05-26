<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('tenants')) {
            Schema::connection('central')->table('tenants', function (Blueprint $table) {
                if (!Schema::connection('central')->hasColumn('tenants', 'collection_fee_percent')) {
                    $table->decimal('collection_fee_percent', 5, 2)->nullable()->after('radius_secret');
                }
                if (!Schema::connection('central')->hasColumn('tenants', 'disbursement_fee_percent')) {
                    $table->decimal('disbursement_fee_percent', 5, 2)->nullable()->after('collection_fee_percent');
                }
                if (!Schema::connection('central')->hasColumn('tenants', 'minimum_disbursement')) {
                    $table->decimal('minimum_disbursement', 12, 2)->nullable()->after('disbursement_fee_percent');
                }
                if (!Schema::connection('central')->hasColumn('tenants', 'support_notes')) {
                    $table->text('support_notes')->nullable()->after('minimum_disbursement');
                }
            });
        }

        foreach (['super_admins', 'tenant_users'] as $tableName) {
            if (!Schema::connection('central')->hasTable($tableName)) {
                continue;
            }

            Schema::connection('central')->table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::connection('central')->hasColumn($tableName, 'two_factor_enabled')) {
                    $table->boolean('two_factor_enabled')->default(false)->after('is_active');
                }
                if (!Schema::connection('central')->hasColumn($tableName, 'two_factor_secret')) {
                    $table->text('two_factor_secret')->nullable()->after('two_factor_enabled');
                }
                if (!Schema::connection('central')->hasColumn($tableName, 'two_factor_recovery_codes')) {
                    $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
                }
                if (!Schema::connection('central')->hasColumn($tableName, 'two_factor_confirmed_at')) {
                    $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
                }
            });
        }

        if (Schema::connection('central')->hasTable('system_settings')) {
            $now = now();
            $settings = [
                [
                    'key' => 'radius_server_ip',
                    'value' => '129.168.0.42',
                    'type' => 'string',
                    'group' => 'radius',
                    'description' => 'FreeRADIUS server IP address used in generated router and tenant configuration.',
                    'is_public' => false,
                ],
                [
                    'key' => 'radius_auth_port',
                    'value' => '1812',
                    'type' => 'integer',
                    'group' => 'radius',
                    'description' => 'FreeRADIUS authentication port.',
                    'is_public' => false,
                ],
                [
                    'key' => 'radius_acct_port',
                    'value' => '1813',
                    'type' => 'integer',
                    'group' => 'radius',
                    'description' => 'FreeRADIUS accounting port.',
                    'is_public' => false,
                ],
                [
                    'key' => 'two_factor_optional',
                    'value' => 'true',
                    'type' => 'boolean',
                    'group' => 'security',
                    'description' => 'Allow administrators and tenant users to enable authenticator-app 2FA.',
                    'is_public' => false,
                ],
            ];

            foreach ($settings as $setting) {
                DB::connection('central')->table('system_settings')->updateOrInsert(
                    ['key' => $setting['key']],
                    array_merge($setting, ['created_at' => $now, 'updated_at' => $now])
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::connection('central')->hasTable('tenants')) {
            Schema::connection('central')->table('tenants', function (Blueprint $table) {
                $columns = ['collection_fee_percent', 'disbursement_fee_percent', 'minimum_disbursement', 'support_notes'];
                foreach ($columns as $column) {
                    if (Schema::connection('central')->hasColumn('tenants', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        foreach (['super_admins', 'tenant_users'] as $tableName) {
            if (!Schema::connection('central')->hasTable($tableName)) {
                continue;
            }

            Schema::connection('central')->table($tableName, function (Blueprint $table) use ($tableName) {
                $columns = ['two_factor_enabled', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'];
                foreach ($columns as $column) {
                    if (Schema::connection('central')->hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::connection('central')->hasTable('system_settings')) {
            DB::connection('central')->table('system_settings')
                ->whereIn('key', ['radius_server_ip', 'radius_auth_port', 'radius_acct_port', 'two_factor_optional'])
                ->delete();
        }
    }
};
