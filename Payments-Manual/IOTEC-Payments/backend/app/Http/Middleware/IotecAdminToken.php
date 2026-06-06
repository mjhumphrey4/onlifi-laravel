<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IotecAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) env('IOTEC_ADMIN_TOKEN', '');
        $providedToken = (string) $request->bearerToken();

        if ($configuredToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthorized. Use the IOTEC admin token.',
            ], 401);
        }

        return $next($request);
    }
}
