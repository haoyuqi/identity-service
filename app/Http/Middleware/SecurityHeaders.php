<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    /**
     * HSTS lifetime in seconds; one year.
     */
    private const HSTS_MAX_AGE = 31536000;

    public function handle(Request $request, Closure $next): Response
    {
        return $this->apply($request, $next($request));
    }

    public function apply(Request $request, Response $response): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        );

        // Only instruct browsers to pin HTTPS when the request itself arrived over TLS;
        // sending HSTS over plain HTTP is ignored by browsers and hides proxy misconfiguration.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.self::HSTS_MAX_AGE);
        }

        if ($request->is('admin', 'admin/*') || $request->routeIs('login', 'login.*', 'admin.*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
