<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureActiveAdministrator;
use App\Http\Middleware\SecurityHeaders;
use App\Support\SafeExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Registered last so it is the outermost middleware: the identifier exists before any
        // other middleware runs and is therefore available to error rendering and logging.
        $middleware->prepend(SecurityHeaders::class);
        $middleware->prepend(AssignRequestId::class);
        $middleware->redirectUsersTo('/admin');

        // Authenticate and validate the session before route model binding so unauthenticated
        // callers cannot tell an existing account identifier from a missing one.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureActiveAdministrator::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(
            fn (Throwable $exception): bool => app(SafeExceptionHandler::class)->report($exception),
        );
        $exceptions->render(
            fn (Throwable $exception, Request $request) => app(SafeExceptionHandler::class)->render($exception, $request),
        );
        $exceptions->respond(
            fn (Response $response) => app(AssignRequestId::class)->apply(
                request(),
                app(SecurityHeaders::class)->apply(request(), $response),
            ),
        );
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
