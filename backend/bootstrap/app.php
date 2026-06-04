<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->prepend(\App\Http\Middleware\CorsPreflight::class);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'tenant' => \App\Http\Middleware\IdentifyTenant::class,
            'tenant.admin' => \App\Http\Middleware\EnsureTenantAdmin::class,
            'super.admin' => \App\Http\Middleware\SuperAdminAuth::class,
            'tenant.billing' => \App\Http\Middleware\EnsureTenantBillingCurrent::class,
            'tenant.permission' => \App\Http\Middleware\EnsureTenantPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->respond(function ($response) {
            $request = request();
            if (!$request->is('api/*')) {
                return $response;
            }

            $origin = $request->headers->get('Origin');
            if (!$origin) {
                return $response;
            }

            $configuredOrigins = config('cors.allowed_origins', []);
            $allowedOrigins = is_array($configuredOrigins)
                ? array_filter(array_map('trim', $configuredOrigins))
                : array_filter(array_map('trim', explode(',', (string) $configuredOrigins)));
            $allowedPatterns = array_filter((array) config('cors.allowed_origins_patterns', []));
            $allowed = in_array($origin, $allowedOrigins, true);

            if (!$allowed) {
                foreach ($allowedPatterns as $pattern) {
                    if (@preg_match($pattern, $origin) === 1) {
                        $allowed = true;
                        break;
                    }
                }
            }

            if ($allowed) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-Site-ID, Accept');
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->headers->set('Vary', 'Origin');
            }

            return $response;
        });
    })->create();
