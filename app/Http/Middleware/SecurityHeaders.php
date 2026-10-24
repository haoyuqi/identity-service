<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return $this->apply($request, $next($request));
    }

    public function apply(Request $request, Response $response): Response
    {

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        );

        if ($request->is('admin', 'admin/*') || $request->routeIs('login', 'login.*', 'admin.*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
