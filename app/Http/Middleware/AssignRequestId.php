<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach a correlation identifier to every request and echo it back.
 *
 * The identifier is only trusted from the client when it is short and
 * alphanumeric-dashed; anything else is replaced so log context and response
 * headers cannot carry caller-controlled content.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    private const ATTRIBUTE = 'request_id';

    private const CLIENT_PATTERN = '/^[A-Za-z0-9._-]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);
        $request->attributes->set(self::ATTRIBUTE, $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    public function apply(Request $request, Response $response): Response
    {
        $response->headers->set(self::HEADER, $this->requestId($request));

        return $response;
    }

    private function requestId(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $clientValue = $request->headers->get(self::HEADER);
        $requestId = is_string($clientValue) && preg_match(self::CLIENT_PATTERN, $clientValue) === 1
            ? $clientValue
            : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        return $requestId;
    }
}
