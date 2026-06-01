<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTenantAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->isAdmin()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Tenant administrator access is required.',
        ], 403);
    }
}
