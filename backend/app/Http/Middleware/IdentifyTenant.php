<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = $this->resolveTenant($request);

        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant not identified',
                'message' => 'Please provide valid API credentials',
            ], 401);
        }

        if (!$tenant->is_active) {
            return response()->json([
                'error' => 'Tenant inactive',
                'message' => 'Your account has been deactivated',
            ], 403);
        }

        if (!$tenant->canAccess()) {
            return response()->json([
                'error' => 'Access denied',
                'message' => 'Your trial has expired or subscription is inactive',
            ], 403);
        }

        $tenant->configure();

        $request->attributes->set('tenant', $tenant);
        app()->instance('tenant', $tenant);

        Log::info('Tenant identified', [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'database' => $tenant->database_name,
        ]);

        return $next($request);
    }

    protected function resolveTenant(Request $request): ?Tenant
    {
        $apiKey = $request->header('X-API-Key') ?? $request->query('api_key');
        $apiSecret = $request->header('X-API-Secret') ?? $request->query('api_secret');

        if ($apiKey && $apiSecret) {
            $tenant = Tenant::where('api_key', $apiKey)->first();
            
            if ($tenant && $tenant->verifyApiSecret($apiSecret)) {
                return $tenant;
            }
        }

        $domain = $request->getHost();
        $subdomain = explode('.', $domain)[0];

        if ($subdomain && $subdomain !== 'www') {
            $tenant = Tenant::where('slug', $subdomain)
                ->orWhere('domain', $domain)
                ->first();
            
            if ($tenant) {
                return $tenant;
            }
        }

        return null;
    }
}
