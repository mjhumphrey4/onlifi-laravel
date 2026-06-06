<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualCallbackEndpoint extends Model
{
    protected $fillable = [
        'name',
        'event',
        'method',
        'url',
        'headers',
        'signing_secret',
        'is_active',
        'last_status',
        'last_called_at',
        'notes',
    ];

    protected $casts = [
        'headers' => 'encrypted:array',
        'signing_secret' => 'encrypted',
        'is_active' => 'boolean',
        'last_called_at' => 'datetime',
    ];

    public function toAdminArray(bool $masked = true): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'event' => $this->event,
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $masked ? $this->maskArray($this->headers ?? []) : ($this->headers ?? []),
            'signing_secret' => $masked && $this->signing_secret ? '********' : ($this->signing_secret ?? ''),
            'is_active' => $this->is_active,
            'last_status' => $this->last_status,
            'last_called_at' => $this->last_called_at?->toIso8601String(),
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function maskArray(array $values): array
    {
        return collect($values)->map(fn ($value) => $value === '' || $value === null ? '' : '********')->all();
    }
}
