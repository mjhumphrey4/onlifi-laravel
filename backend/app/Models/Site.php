<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Site extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'api_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Don't hide api_token - it's needed for telemetry script generation
    protected $hidden = [];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($site) {
            if (empty($site->slug)) {
                $site->slug = Str::slug($site->name);
            }
            if (empty($site->api_token)) {
                $site->api_token = Str::random(64);
            }
        });
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
