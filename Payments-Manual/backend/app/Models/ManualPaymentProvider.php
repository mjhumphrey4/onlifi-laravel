<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualPaymentProvider extends Model
{
    protected $fillable = [
        'name',
        'code',
        'provider_type',
        'status',
        'priority',
        'base_url',
        'callback_url',
        'credentials',
        'settings',
        'notes',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'priority' => 'integer',
    ];

    public function toAdminArray(bool $masked = true): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'provider_type' => $this->provider_type,
            'status' => $this->status,
            'priority' => $this->priority,
            'base_url' => $this->base_url,
            'callback_url' => $this->callback_url,
            'credentials' => $masked ? $this->maskArray($this->credentials ?? []) : ($this->credentials ?? []),
            'settings' => $this->settings ?? [],
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function maskArray(array $values): array
    {
        return collect($values)->map(fn ($value) => $value === '' || $value === null ? '' : '********')->all();
    }
}
