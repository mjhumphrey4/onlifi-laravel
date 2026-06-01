<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class TenantUser extends Authenticatable
{
    use Notifiable, HasApiTokens;

    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
        'allowed_site_ids',
        'permissions',
        'created_by',
        'is_active',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'allowed_site_ids' => 'array',
        'permissions' => 'array',
        'two_factor_enabled' => 'boolean',
        'two_factor_confirmed_at' => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isSubUser(): bool
    {
        return $this->role === 'sub_user';
    }

    public function canUsePermission(string $permission): bool
    {
        return $this->isAdmin() || in_array($permission, $this->permissions ?: [], true);
    }
}
