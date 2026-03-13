<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class SuperAdmin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $connection = 'central';
    protected $table = 'super_admins';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function canManageTenants(): bool
    {
        return in_array($this->role, ['super_admin', 'admin']);
    }

    public function canManageSettings(): bool
    {
        return $this->role === 'super_admin';
    }

    public function approvedTenants()
    {
        return $this->hasMany(Tenant::class, 'approved_by');
    }

    public function announcements()
    {
        return $this->hasMany(Announcement::class, 'created_by');
    }
}
