<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SupportTicketController extends Controller
{
    public function tenantIndex(Request $request)
    {
        $tenantId = $this->tenantId($request);
        if (!$tenantId) {
            return response()->json(['message' => 'Tenant account required.'], 403);
        }

        $query = SupportTicket::with(['user:id,name,email'])
            ->withCount('messages')
            ->where('tenant_id', $tenantId);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest('last_message_at')->paginate(20));
    }

    public function tenantStore(Request $request)
    {
        $tenantId = $this->tenantId($request);
        if (!$tenantId) {
            return response()->json(['message' => 'Tenant account required.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:255',
            'category' => 'nullable|string|max:80',
            'priority' => 'required|in:low,normal,high,urgent',
            'body' => 'required|string|min:3',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $ticket = DB::connection('central')->transaction(function () use ($request, $tenantId) {
            $ticket = SupportTicket::create([
                'tenant_id' => $tenantId,
                'tenant_user_id' => $request->user()->id,
                'subject' => $request->subject,
                'category' => $request->category ?: 'general',
                'priority' => $request->priority,
                'status' => 'open',
                'last_reply_by' => 'tenant',
                'unread_for_admin' => true,
                'unread_for_tenant' => false,
                'last_message_at' => now(),
            ]);

            $ticket->messages()->create([
                'sender_type' => 'tenant',
                'sender_id' => $request->user()->id,
                'body' => $request->body,
            ]);

            return $ticket;
        });

        return response()->json([
            'message' => 'Support ticket created',
            'ticket' => $this->ticketPayload($ticket->fresh(['tenant', 'user', 'messages'])),
        ], 201);
    }

    public function tenantShow(Request $request, int $id)
    {
        $ticket = $this->tenantTicket($request, $id);
        if (!$ticket) {
            return response()->json(['message' => 'Support ticket not found'], 404);
        }

        $ticket->update(['unread_for_tenant' => false]);

        return response()->json([
            'ticket' => $this->ticketPayload($ticket->fresh(['tenant', 'user', 'messages'])),
        ]);
    }

    public function tenantUpdate(Request $request, int $id)
    {
        $ticket = $this->tenantTicket($request, $id);
        if (!$ticket) {
            return response()->json(['message' => 'Support ticket not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'sometimes|required|string|max:255',
            'category' => 'nullable|string|max:80',
            'priority' => 'sometimes|required|in:low,normal,high,urgent',
            'body' => 'nullable|string|min:3',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        DB::connection('central')->transaction(function () use ($request, $ticket) {
            $ticket->update($request->only(['subject', 'category', 'priority']));

            if ($request->filled('body')) {
                $firstMessage = $ticket->messages()
                    ->where('sender_type', 'tenant')
                    ->orderBy('id')
                    ->first();

                if ($firstMessage) {
                    $firstMessage->update([
                        'body' => $request->body,
                        'edited_at' => now(),
                    ]);
                }
            }
        });

        return response()->json([
            'message' => 'Support ticket updated',
            'ticket' => $this->ticketPayload($ticket->fresh(['tenant', 'user', 'messages'])),
        ]);
    }

    public function tenantReply(Request $request, int $id)
    {
        $ticket = $this->tenantTicket($request, $id);
        if (!$ticket) {
            return response()->json(['message' => 'Support ticket not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'required|string|min:2',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        DB::connection('central')->transaction(function () use ($request, $ticket) {
            $ticket->messages()->create([
                'sender_type' => 'tenant',
                'sender_id' => $request->user()->id,
                'body' => $request->body,
            ]);

            $ticket->update([
                'status' => in_array($ticket->status, ['resolved', 'closed'], true) ? 'open' : 'pending_admin',
                'last_reply_by' => 'tenant',
                'unread_for_admin' => true,
                'unread_for_tenant' => false,
                'last_message_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Reply added',
            'ticket' => $this->ticketPayload($ticket->fresh(['tenant', 'user', 'messages'])),
        ]);
    }

    public function tenantNotifications(Request $request)
    {
        $tenantId = $this->tenantId($request);
        if (!$tenantId) {
            return response()->json(['notifications' => [], 'count' => 0]);
        }

        $tickets = SupportTicket::where('tenant_id', $tenantId)
            ->where('unread_for_tenant', true)
            ->latest('last_message_at')
            ->limit(20)
            ->get();

        return response()->json([
            'count' => $tickets->count(),
            'notifications' => $tickets->map(fn ($ticket) => $this->notificationPayload($ticket, 'Admin replied to your support ticket')),
        ]);
    }

    public function adminIndex(Request $request)
    {
        $query = SupportTicket::with(['tenant:id,name,slug,status,is_active', 'user:id,name,email'])
            ->withCount('messages');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', $search)
                    ->orWhereHas('tenant', fn ($tenant) => $tenant->where('name', 'like', $search));
            });
        }

        return response()->json($query->latest('last_message_at')->paginate(30));
    }

    public function adminShow(int $id)
    {
        $ticket = SupportTicket::with(['tenant', 'user', 'messages'])->find($id);
        if (!$ticket) {
            return response()->json(['message' => 'Support ticket not found'], 404);
        }

        $ticket->update(['unread_for_admin' => false]);

        return response()->json([
            'ticket' => $this->ticketPayload($ticket->fresh(['tenant', 'user', 'messages'])),
        ]);
    }

    public function adminReply(Request $request, int $id)
    {
        $ticket = SupportTicket::find($id);
        if (!$ticket) {
            return response()->json(['message' => 'Support ticket not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'required|string|min:2',
            'status' => 'nullable|in:open,pending_admin,pending_customer,resolved,closed',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        DB::connection('central')->transaction(function () use ($request, $ticket) {
            $ticket->messages()->create([
                'sender_type' => 'admin',
                'sender_id' => $request->user()->id,
                'body' => $request->body,
            ]);

            $ticket->update([
                'status' => $request->status ?: 'pending_customer',
                'last_reply_by' => 'admin',
                'unread_for_admin' => false,
                'unread_for_tenant' => true,
                'last_message_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Reply sent',
            'ticket' => $this->ticketPayload($ticket->fresh(['tenant', 'user', 'messages'])),
        ]);
    }

    public function adminUpdateStatus(Request $request, int $id)
    {
        $ticket = SupportTicket::find($id);
        if (!$ticket) {
            return response()->json(['message' => 'Support ticket not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:open,pending_admin,pending_customer,resolved,closed',
            'priority' => 'nullable|in:low,normal,high,urgent',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $ticket->update([
            'status' => $request->status,
            'priority' => $request->priority ?: $ticket->priority,
            'unread_for_tenant' => true,
            'last_reply_by' => 'admin',
            'last_message_at' => now(),
        ]);

        return response()->json([
            'message' => 'Support ticket updated',
            'ticket' => $this->ticketPayload($ticket->fresh(['tenant', 'user', 'messages'])),
        ]);
    }

    public function adminNotifications()
    {
        $tickets = SupportTicket::with('tenant:id,name')
            ->where('unread_for_admin', true)
            ->latest('last_message_at')
            ->limit(20)
            ->get();

        return response()->json([
            'count' => $tickets->count(),
            'notifications' => $tickets->map(fn ($ticket) => $this->notificationPayload($ticket, 'New support ticket activity')),
        ]);
    }

    private function tenantTicket(Request $request, int $id): ?SupportTicket
    {
        $tenantId = $this->tenantId($request);

        return $tenantId
            ? SupportTicket::where('tenant_id', $tenantId)->where('id', $id)->first()
            : null;
    }

    private function tenantId(Request $request): ?int
    {
        $user = $request->user();
        return $user && isset($user->tenant_id) ? (int) $user->tenant_id : null;
    }

    private function ticketPayload(SupportTicket $ticket): array
    {
        $ticket->loadMissing(['tenant', 'user', 'messages']);

        return [
            'id' => $ticket->id,
            'tenant_id' => $ticket->tenant_id,
            'tenant' => $ticket->tenant ? [
                'id' => $ticket->tenant->id,
                'name' => $ticket->tenant->name,
                'slug' => $ticket->tenant->slug,
            ] : null,
            'user' => $ticket->user ? [
                'id' => $ticket->user->id,
                'name' => $ticket->user->name,
                'email' => $ticket->user->email,
            ] : null,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'unread_for_admin' => $ticket->unread_for_admin,
            'unread_for_tenant' => $ticket->unread_for_tenant,
            'last_reply_by' => $ticket->last_reply_by,
            'last_message_at' => $ticket->last_message_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'messages' => $ticket->messages
                ->sortBy('created_at')
                ->map(fn ($message) => $this->messagePayload($message))
                ->values(),
        ];
    }

    private function messagePayload(SupportTicketMessage $message): array
    {
        $senderName = 'System';

        if ($message->sender_type === 'tenant') {
            $senderName = optional($message->tenantUser()->first())->name ?: 'Tenant';
        }

        if ($message->sender_type === 'admin') {
            $senderName = optional($message->admin()->first())->name ?: 'Admin';
        }

        return [
            'id' => $message->id,
            'sender_type' => $message->sender_type,
            'sender_id' => $message->sender_id,
            'sender_name' => $senderName,
            'body' => $message->body,
            'edited_at' => $message->edited_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function notificationPayload(SupportTicket $ticket, string $title): array
    {
        return [
            'id' => 'ticket-' . $ticket->id . '-' . optional($ticket->last_message_at)->timestamp,
            'ticket_id' => $ticket->id,
            'title' => $title,
            'content' => '#' . $ticket->id . ' ' . $ticket->subject,
            'type' => $ticket->priority === 'urgent' ? 'error' : ($ticket->priority === 'high' ? 'warning' : 'info'),
            'created_at' => $ticket->last_message_at?->toIso8601String() ?: $ticket->updated_at?->toIso8601String(),
        ];
    }
}
