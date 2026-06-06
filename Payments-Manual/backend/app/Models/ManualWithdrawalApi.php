<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualWithdrawalApi extends Model
{
    protected $fillable = [
        'name',
        'provider_code',
        'status',
        'base_url',
        'credentials',
        'settings',
        'daily_limit',
        'minimum_amount',
        'notes',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'daily_limit' => 'decimal:2',
        'minimum_amount' => 'decimal:2',
    ];

    public function toAdminArray(bool $masked = true): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider_code' => $this->provider_code,
            'status' => $this->status,
            'base_url' => $this->base_url,
            'credentials' => $masked ? $this->maskArray($this->credentials ?? []) : ($this->credentials ?? []),
            'settings' => $this->settings ?? [],
            'daily_limit' => (float) $this->daily_limit,
            'minimum_amount' => (float) $this->minimum_amount,
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function maskArray(array $values): array
    {
        return collect($values)->map(fn ($value) => $value === '' || $value === null ? '' : '********')->all();
    }
}
