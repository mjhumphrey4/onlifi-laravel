<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTenantBillingCurrent
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = app()->bound('tenant') ? app('tenant') : $request->user()?->tenant;

        if ($tenant && ($tenant->billingStatus()['requires_payment'] ?? false)) {
            return response()->json([
                'message' => 'Subscription renewal required',
                'billing' => $tenant->billingStatus(),
            ], 402);
        }

        return $next($request);
    }
}
