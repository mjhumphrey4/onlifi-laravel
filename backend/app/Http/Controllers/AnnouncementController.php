<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $query = Announcement::with('creator');

        if ($request->has('active_only')) {
            $query->active();
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $announcements = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($announcements);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|in:info,warning,success,error',
            'target' => 'required|in:all,active,trial,specific',
            'tenant_ids' => 'required_if:target,specific|array',
            'tenant_ids.*' => 'exists:tenants,id',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $announcement = Announcement::create([
            'title' => $request->title,
            'content' => $request->content,
            'type' => $request->type,
            'target' => $request->target,
            'tenant_ids' => $request->tenant_ids,
            'is_active' => $request->is_active ?? true,
            'starts_at' => $request->starts_at,
            'ends_at' => $request->ends_at,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Announcement created successfully',
            'announcement' => $announcement->load('creator'),
        ], 201);
    }

    public function show(Announcement $announcement)
    {
        return response()->json($announcement->load('creator'));
    }

    public function update(Request $request, Announcement $announcement)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'string|max:255',
            'content' => 'string',
            'type' => 'in:info,warning,success,error',
            'target' => 'in:all,active,trial,specific',
            'tenant_ids' => 'required_if:target,specific|array',
            'tenant_ids.*' => 'exists:tenants,id',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $announcement->update($request->only([
            'title',
            'content',
            'type',
            'target',
            'tenant_ids',
            'is_active',
            'starts_at',
            'ends_at',
        ]));

        return response()->json([
            'message' => 'Announcement updated successfully',
            'announcement' => $announcement->fresh()->load('creator'),
        ]);
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();

        return response()->json([
            'message' => 'Announcement deleted successfully',
        ]);
    }

    public function forTenant(Request $request)
    {
        $tenantId = $request->header('X-Tenant-ID');

        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant ID required',
            ], 400);
        }

        $announcements = Announcement::active()
            ->forTenant($tenantId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($announcements);
    }
}
