<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'tenant_user_id',
        'subject',
        'category',
        'priority',
        'status',
        'last_reply_by',
        'unread_for_admin',
        'unread_for_tenant',
        'last_message_at',
    ];

    protected $casts = [
        'unread_for_admin' => 'boolean',
        'unread_for_tenant' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id');
    }

    public function messages()
    {
        return $this->hasMany(SupportTicketMessage::class);
    }
}
