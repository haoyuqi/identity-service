<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SafeExceptionHandler
{
    /**
     * Report a failure using only non-sensitive diagnostics.
     *
     * Exception messages, SQL, bindings, request values, and headers are deliberately
     * excluded; the correlation identifier ties a report to its response without
     * recording any of that material.
     */
    public function report(Throwable $exception): bool
    {
        $requestId = request()->attributes->get('request_id');
        $context = [
            'exception_class' => $exception::class,
            'exception_code' => $exception->getCode(),
            'exception_chain' => $this->exceptionChain($exception),
            'sqlstate' => $this->sqlState($exception),
        ];

        if (is_string($requestId) && $requestId !== '') {
            $context['request_id'] = $requestId;
        }

        $route = request()->route();

        if ($route !== null) {
            $context['route'] = $route->uri();
        }

        Log::error('HTTP request failed.', array_filter($context, static fn (mixed $value): bool => $value !== null));

        return false;
    }

    public function render(Throwable $exception, Request $request): ?Response
    {
        if ($this->isRedisFailure($exception)) {
            return $this->genericResponse($request, 503, 'Service temporarily unavailable.');
        }

        if ($exception instanceof HttpExceptionInterface && app()->isProduction()) {
            return $this->httpExceptionResponse($request, $exception);
        }

        if (! app()->isProduction()) {
            return null;
        }

        if ($this->usesFrameworkResponse($exception)) {
            return null;
        }

        return $this->genericResponse($request, 500, 'Server error.');
    }

    /**
     * @return array<int, class-string<Throwable>>
     */
    private function exceptionChain(Throwable $exception): array
    {
        $chain = [];

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $chain[] = $current::class;
        }

        return $chain;
    }

    private function sqlState(Throwable $exception): ?string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof QueryException) {
                $state = $current->errorInfo[0] ?? null;

                return is_string($state) && $state !== '' ? $state : null;
            }

            if ($current instanceof PDOException) {
                $state = $current->errorInfo[0] ?? $current->getCode();

                return is_string($state) && $state !== '' ? $state : null;
            }
        }

        return null;
    }

    private function isRedisFailure(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (in_array($current::class, [
                'RedisException',
                'RedisClusterException',
                'Predis\\Connection\\ConnectionException',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    private function usesFrameworkResponse(Throwable $exception): bool
    {
        return $exception instanceof AuthenticationException
            || $exception instanceof ValidationException
            || $exception instanceof HttpResponseException;
    }

    private function httpExceptionResponse(Request $request, HttpExceptionInterface $exception): Response
    {
        $status = $exception->getStatusCode();
        $message = $status === Response::HTTP_INTERNAL_SERVER_ERROR
            ? 'Server error.'
            : (Response::$statusTexts[$status] ?? 'Request failed.');

        $response = $this->genericResponse($request, $status, $message);

        foreach (['Allow', 'Retry-After', 'WWW-Authenticate'] as $header) {
            $value = $exception->getHeaders()[$header] ?? null;

            if ($value !== null) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }

    private function genericResponse(Request $request, int $status, string $message): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return response($message, $status)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
