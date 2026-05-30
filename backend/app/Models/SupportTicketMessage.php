<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'support_ticket_id',
        'sender_type',
        'sender_id',
        'body',
        'edited_at',
    ];

    protected $casts = [
        'edited_at' => 'datetime',
    ];

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function tenantUser()
    {
        return $this->belongsTo(TenantUser::class, 'sender_id');
    }

    public function admin()
    {
        return $this->belongsTo(SuperAdmin::class, 'sender_id');
    }
}
