<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IotecCallbackEndpoint extends Model
{
    protected $fillable = [
        'name',
        'event',
        'method',
        'url',
        'expected_fields',
        'headers',
        'is_active',
        'last_status',
        'last_called_at',
        'notes',
    ];

    protected $casts = [
        'expected_fields' => 'array',
        'headers' => 'encrypted:array',
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
            'expected_fields' => $this->expected_fields ?? [],
            'headers' => $masked ? $this->maskArray($this->headers ?? []) : ($this->headers ?? []),
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
