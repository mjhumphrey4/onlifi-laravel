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
                if (!Schema::connection('central')->hasColumn('tenants', 'sms_enabled')) {
                    $table->boolean('sms_enabled')->default(true)->after('support_notes');
                }
                if (!Schema::connection('central')->hasColumn('tenants', 'sms_provider_config')) {
                    $table->json('sms_provider_config')->nullable()->after('sms_enabled');
                }
            });
        }

        if (!Schema::connection('central')->hasTable('system_settings')) {
            return;
        }

        $settings = [
            ['mail_mailer', 'smtp', 'string', 'email', 'Mail transport to use'],
            ['payment_gateway', 'yo', 'string', 'payment', 'Active payment gateway'],
            ['smtp_host', '127.0.0.1', 'string', 'email', 'SMTP server hostname'],
            ['smtp_port', '587', 'integer', 'email', 'SMTP server port'],
            ['smtp_username', '', 'string', 'email', 'SMTP username'],
            ['smtp_password', '', 'string', 'email', 'SMTP password'],
            ['smtp_encryption', 'tls', 'string', 'email', 'SMTP encryption: tls, ssl, or empty'],
            ['smtp_from_address', 'noreply@onlifi.local', 'string', 'email', 'Default from email address'],
            ['smtp_from_name', 'OnLiFi', 'string', 'email', 'Default from name'],
            ['notify_signup_email', '1', 'boolean', 'notifications', 'Email tenant after signup'],
            ['notify_activation_email', '1', 'boolean', 'notifications', 'Email tenant when account is approved'],
            ['notify_password_reset_email', '1', 'boolean', 'notifications', 'Email tenant after password reset'],
            ['notify_announcement_email', '0', 'boolean', 'notifications', 'Allow announcements to be sent by email'],
            ['api_base_url', 'http://api.onlifi.net', 'string', 'domains', 'Public API base URL used by routers and generated hotspot files'],
            ['dashboard_url', 'http://onlifi.net', 'string', 'domains', 'Public dashboard URL used in emails and frontend links'],
            ['manual_payment_base_url', 'http://pay.onlifi.net', 'string', 'domains', 'Base URL for manually managed captive payment folders'],
            ['router_admin_username', 'onlifi', 'string', 'router', 'Dedicated RouterOS administrator username for remote telemetry access'],
            ['router_admin_password', 'onlifi-router-admin-change-me', 'string', 'router', 'Dedicated RouterOS administrator password for remote telemetry access'],
            ['router_remote_vpn_cidr', '10.10.1.0/24', 'string', 'router', 'SSTP VPN subnet allowed to access router API'],
        ];

        foreach ($settings as [$key, $value, $type, $group, $description]) {
            DB::connection('central')->table('system_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => $value,
                    'type' => $type,
                    'group' => $group,
                    'description' => $description,
                    'is_public' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::connection('central')->hasTable('tenants')) {
            Schema::connection('central')->table('tenants', function (Blueprint $table) {
                if (Schema::connection('central')->hasColumn('tenants', 'sms_provider_config')) {
                    $table->dropColumn('sms_provider_config');
                }
                if (Schema::connection('central')->hasColumn('tenants', 'sms_enabled')) {
                    $table->dropColumn('sms_enabled');
                }
            });
        }
    }
};
