<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Site extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'site_type',
        'omada_site_name',
        'omada_site_id',
        'omada_controller_id',
        'omada_link_status',
        'omada_linked_at',
        'is_active',
        'api_token',
        'database_name',
        'database_host',
        'database_port',
        'database_username',
        'database_password',
        'vpn_private_ip',
        'vpn_username',
        'vpn_password',
        'vpn_public_host',
        'vpn_public_port',
        'remote_access_port',
        'vpn_status',
        'vpn_last_seen_at',
        'wireguard_private_key',
        'wireguard_public_key',
        'wireguard_preshared_key',
        'router_api_port',
        'remote_access_notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'vpn_last_seen_at' => 'datetime',
        'omada_linked_at' => 'datetime',
        'database_port' => 'integer',
        'router_api_port' => 'integer',
        'vpn_public_port' => 'integer',
        'remote_access_port' => 'integer',
    ];

    protected $hidden = [
        'database_password',
        'wireguard_private_key',
        'wireguard_preshared_key',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($site) {
            if (empty($site->slug)) {
                $site->slug = self::uniqueSlug($site->name);
            }
            if (empty($site->api_token)) {
                $site->api_token = Str::random(64);
            }
            if (empty($site->vpn_username)) {
                $site->vpn_username = Str::slug($site->name) ?: 'site';
            }
            if (empty($site->vpn_password)) {
                $site->vpn_password = Str::random(24);
            }
            if (empty($site->vpn_public_host)) {
                $site->vpn_public_host = '89.167.42.53';
            }
            if (empty($site->vpn_public_port)) {
                $site->vpn_public_port = self::defaultVpnPublicPort();
            }
            if (self::hasRemoteAccessPortColumn() && empty($site->remote_access_port)) {
                $site->remote_access_port = self::uniqueRemoteAccessPort();
            }
            if (empty($site->vpn_status) || $site->vpn_status === 'pending') {
                $site->vpn_status = 'active';
            }
            if (empty($site->wireguard_private_key) || empty($site->wireguard_public_key)) {
                $keys = self::generateWireGuardKeyPair();
                $site->wireguard_private_key = $site->wireguard_private_key ?: $keys['private_key'];
                $site->wireguard_public_key = $site->wireguard_public_key ?: $keys['public_key'];
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'site';
        $slug = $base;
        $counter = 1;

        while (
            self::where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    public static function uniqueVpnPublicPort(?int $ignoreId = null): int
    {
        return self::defaultVpnPublicPort();
    }

    public static function uniqueRemoteAccessPort(?int $ignoreId = null): int
    {
        $start = 32000;
        $max = 65535;

        for ($port = $start; $port <= $max; $port++) {
            $exists = self::query()
                ->where('remote_access_port', $port)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists();

            if (!$exists) {
                return $port;
            }
        }

        throw new \RuntimeException('No available remote access ports remain.');
    }

    public static function defaultVpnPublicPort(): int
    {
        return 51820;
    }

    public static function hasRemoteAccessPortColumn(): bool
    {
        try {
            return Schema::connection('central')->hasColumn('sites', 'remote_access_port');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function generateWireGuardKeyPair(): array
    {
        $privateKey = random_bytes(32);
        $privateKey[0] = chr(ord($privateKey[0]) & 248);
        $privateKey[31] = chr((ord($privateKey[31]) & 127) | 64);

        $publicKey = function_exists('sodium_crypto_scalarmult_base')
            ? sodium_crypto_scalarmult_base($privateKey)
            : random_bytes(32);

        return [
            'private_key' => base64_encode($privateKey),
            'public_key' => base64_encode($publicKey),
        ];
    }

    public function routers()
    {
        return $this->hasMany(MikrotikRouter::class);
    }

    public function voucherGroups()
    {
        return $this->hasMany(VoucherGroup::class);
    }

    public function configureTenantConnection(Tenant $tenant): void
    {
        Config::set('database.connections.tenant', [
            'driver' => 'mysql',
            'host' => $this->database_host ?: $tenant->database_host,
            'port' => $this->database_port ?: $tenant->database_port,
            'database' => $this->database_name ?: $tenant->database_name,
            'username' => $this->database_username ?: $tenant->database_username,
            'password' => $this->database_password ?: $tenant->database_password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ]);

        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    public function useTenantDatabase(Tenant $tenant): void
    {
        $this->update([
            'database_name' => $tenant->database_name,
            'database_host' => $tenant->database_host,
            'database_port' => $tenant->database_port,
            'database_username' => $tenant->database_username,
            'database_password' => $tenant->database_password,
        ]);
    }

    public function provisionDatabase(Tenant $tenant): void
    {
        $centralUsername = config('database.connections.central.username');
        $centralPassword = config('database.connections.central.password');
        $centralHost = config('database.connections.central.host');
        $databaseName = $this->database_name ?: $this->generateDatabaseName($tenant);

        DB::connection('central')->statement("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->update([
            'database_name' => $databaseName,
            'database_host' => $centralHost,
            'database_port' => config('database.connections.central.port', 3306),
            'database_username' => $centralUsername,
            'database_password' => $centralPassword,
        ]);

        $this->fresh()->configureTenantConnection($tenant);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    private function generateDatabaseName(Tenant $tenant): string
    {
        $sitePart = Str::slug($this->slug ?: $this->name, '_') ?: 'site';
        $sitePart = Str::limit($sitePart, 24, '');

        return 'onlifi_' . $tenant->id . '_' . $this->id . '_' . $sitePart;
    }

    public function regenerateApiToken(): string
    {
        $this->api_token = Str::random(64);
        $this->save();
        return $this->api_token;
    }
}
