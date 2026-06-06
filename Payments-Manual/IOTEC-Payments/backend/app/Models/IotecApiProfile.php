<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IotecApiProfile extends Model
{
    protected $fillable = [
        'name',
        'code',
        'status',
        'environment',
        'auth_url',
        'api_base_url',
        'wallet_id',
        'client_id',
        'client_secret',
        'callback_url',
        'default_currency',
        'default_category',
        'settings',
        'notes',
    ];

    protected $casts = [
        'client_secret' => 'encrypted',
        'settings' => 'array',
    ];

    public function toAdminArray(bool $masked = true): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'status' => $this->status,
            'environment' => $this->environment,
            'auth_url' => $this->auth_url,
            'api_base_url' => $this->api_base_url,
            'wallet_id' => $this->wallet_id,
            'client_id' => $this->client_id,
            'client_secret' => $masked && $this->client_secret ? '********' : ($this->client_secret ?? ''),
            'callback_url' => $this->callback_url,
            'default_currency' => $this->default_currency,
            'default_category' => $this->default_category,
            'settings' => $this->settings ?? [],
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
