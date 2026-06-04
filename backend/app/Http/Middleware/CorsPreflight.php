<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsPreflight
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS') && $request->is('api/*')) {
            return $this->withCorsHeaders(response()->noContent(), $request);
        }

        $response = $next($request);

        if ($request->is('api/*')) {
            return $this->withCorsHeaders($response, $request);
        }

        return $response;
    }

    private function withCorsHeaders(Response $response, Request $request): Response
    {
        $origin = $request->headers->get('Origin');
        if (!$origin || !$this->originAllowed($origin)) {
            return $response;
        }

        $requestedHeaders = $request->headers->get('Access-Control-Request-Headers');

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', $requestedHeaders ?: 'Content-Type, Authorization, X-Requested-With, X-Site-ID, Accept');
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Disposition');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');

        return $response;
    }

    private function originAllowed(string $origin): bool
    {
        $configuredOrigins = config('cors.allowed_origins', []);
        $allowedOrigins = is_array($configuredOrigins)
            ? array_filter(array_map('trim', $configuredOrigins))
            : array_filter(array_map('trim', explode(',', (string) $configuredOrigins)));

        if (in_array($origin, $allowedOrigins, true)) {
            return true;
        }

        foreach (array_filter((array) config('cors.allowed_origins_patterns', [])) as $pattern) {
            if (@preg_match($pattern, $origin) === 1) {
                return true;
            }
        }

        return false;
    }
}
