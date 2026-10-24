<?php

use App\Http\Middleware\SecurityHeaders;
use App\Support\SafeExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(SecurityHeaders::class);
        $middleware->redirectUsersTo('/admin');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(
            fn (Throwable $exception): bool => app(SafeExceptionHandler::class)->report($exception),
        );
        $exceptions->render(
            fn (Throwable $exception, Request $request) => app(SafeExceptionHandler::class)->render($exception, $request),
        );
        $exceptions->respond(
            fn (Response $response) => app(SecurityHeaders::class)->apply(request(), $response),
        );

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
