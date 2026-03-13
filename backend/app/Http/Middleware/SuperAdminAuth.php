<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth('sanctum')->check()) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Please login to access admin panel',
            ], 401);
        }

        $user = auth('sanctum')->user();

        if (!$user instanceof \App\Models\SuperAdmin) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Access denied. Super admin privileges required.',
            ], 403);
        }

        if (!$user->is_active) {
            return response()->json([
                'error' => 'Account inactive',
                'message' => 'Your admin account has been deactivated',
            ], 403);
        }

        return $next($request);
    }
}
