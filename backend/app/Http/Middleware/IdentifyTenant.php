<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = $this->resolveTenant($request);

        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant not identified',
                'message' => 'Please login or provide valid API credentials',
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
                'message' => 'Your account is not approved or active',
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
        // 1. First, try to resolve from authenticated tenant user (Sanctum token)
        $bearerToken = $request->bearerToken();
        if ($bearerToken) {
            $token = PersonalAccessToken::findToken($bearerToken);
            if ($token && $token->tokenable_type === TenantUser::class) {
                $tenantUser = $token->tokenable;
                if ($tenantUser && $tenantUser->tenant) {
                    return $tenantUser->tenant;
                }
            }
        }

        // 2. Try API key/secret authentication
        $apiKey = $request->header('X-API-Key') ?? $request->query('api_key');
        $apiSecret = $request->header('X-API-Secret') ?? $request->query('api_secret');

        if ($apiKey && $apiSecret) {
            $tenant = Tenant::where('api_key', $apiKey)->first();
            
            if ($tenant && $tenant->verifyApiSecret($apiSecret)) {
                return $tenant;
            }
        }

        // 3. Try domain/subdomain resolution
        $domain = $request->getHost();
        $subdomain = explode('.', $domain)[0];

        if ($subdomain && $subdomain !== 'www' && $subdomain !== 'localhost') {
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
