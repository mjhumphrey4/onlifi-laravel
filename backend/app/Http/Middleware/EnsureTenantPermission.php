<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTenantPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'sub_user') {
            return $next($request);
        }

        if (in_array($permission, $user->permissions ?: [], true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'You do not have permission to access this section.',
        ], 403);
    }
}
