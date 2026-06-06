<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IotecCorsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)->withHeaders($this->headers($request));
        }

        $response = $next($request);

        foreach ($this->headers($request) as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
    }

    private function headers(Request $request): array
    {
        $origin = $request->headers->get('origin', '*');

        return [
            'Access-Control-Allow-Origin' => $origin ?: '*',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin',
            'Vary' => 'Origin',
        ];
    }
}
