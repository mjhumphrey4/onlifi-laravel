<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ManualPaymentSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'encrypted_value',
        'type',
        'group',
        'label',
        'description',
        'is_sensitive',
    ];

    protected $casts = [
        'is_sensitive' => 'boolean',
    ];

    public static function value(string $key, mixed $default = null): mixed
    {
        $setting = self::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        return $setting->decodedValue();
    }

    public static function put(string $key, mixed $value, array $attributes = []): self
    {
        $setting = self::firstOrNew(['key' => $key]);
        $setting->fill($attributes);
        $setting->type = $attributes['type'] ?? $setting->type ?? 'string';
        $setting->is_sensitive = $attributes['is_sensitive'] ?? $setting->is_sensitive ?? false;
        $setting->setDecodedValue($value);
        $setting->save();

        return $setting;
    }

    public function setDecodedValue(mixed $value): void
    {
        $serialized = is_array($value) ? json_encode($value) : (string) $value;

        if ($this->is_sensitive) {
            $this->encrypted_value = $serialized === '' ? null : Crypt::encryptString($serialized);
            $this->value = null;
            return;
        }

        $this->value = $serialized;
        $this->encrypted_value = null;
    }

    public function decodedValue(bool $masked = false): mixed
    {
        if ($this->is_sensitive) {
            if ($masked) {
                return $this->encrypted_value ? '********' : '';
            }

            $raw = $this->encrypted_value ? Crypt::decryptString($this->encrypted_value) : '';
        } else {
            $raw = $this->value;
        }

        return match ($this->type) {
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $raw,
            'float' => (float) $raw,
            'json', 'array' => json_decode($raw ?: '[]', true) ?: [],
            default => $raw,
        };
    }

    public function toAdminArray(bool $masked = true): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'value' => $this->decodedValue($masked),
            'type' => $this->type,
            'group' => $this->group,
            'label' => $this->label,
            'description' => $this->description,
            'is_sensitive' => $this->is_sensitive,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
