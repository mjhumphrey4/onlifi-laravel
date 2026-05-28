<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Site extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'is_active',
        'api_token',
        'vpn_private_ip',
        'vpn_username',
        'vpn_password',
        'vpn_public_host',
        'vpn_public_port',
        'vpn_status',
        'vpn_last_seen_at',
        'router_api_port',
        'remote_access_notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'vpn_last_seen_at' => 'datetime',
        'router_api_port' => 'integer',
        'vpn_public_port' => 'integer',
    ];

    // Don't hide api_token - it's needed for telemetry script generation
    protected $hidden = [];

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
                $site->vpn_public_host = 'vpn.onlifi.net';
            }
            if (empty($site->vpn_public_port)) {
                $site->vpn_public_port = self::uniqueVpnPublicPort();
            }
            if (empty($site->vpn_status) || $site->vpn_status === 'pending') {
                $site->vpn_status = 'active';
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
        do {
            $port = random_int(20000, 65000);
            $exists = self::where('vpn_public_port', $port)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists();
        } while ($exists);

        return $port;
    }

    public function routers()
    {
        return $this->hasMany(MikrotikRouter::class);
    }

    public function voucherGroups()
    {
        return $this->hasMany(VoucherGroup::class);
    }

    public function regenerateApiToken(): string
    {
        $this->api_token = Str::random(64);
        $this->save();
        return $this->api_token;
    }
}
