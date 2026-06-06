<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ManualAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) env('PAYMENTS_MANUAL_ADMIN_TOKEN', '');
        $providedToken = (string) $request->bearerToken();

        if ($configuredToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthorized. Use the payments-manual admin token.',
            ], 401);
        }

        return $next($request);
    }
}
