<?php

use App\Http\ApiErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Inventory\Exceptions\InventoryRejectedException;
use App\Modules\Inventory\Exceptions\InventoryServiceException;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'role' => EnsureUserHasRole::class,
    ]);
    $middleware->append(
        SecurityHeaders::class
    );
})

->withExceptions(function (Exceptions $exceptions): void {

    $exceptions->shouldRenderJsonWhen(
        fn (Request $request, \Throwable $exception) =>
            $request->is('api/*') || $request->expectsJson()
    );

    $exceptions->render(function (ValidationException $exception, Request $request) {
        if (!$request->is('api/*')) {
            return null;
        }

        return ApiErrorResponse::make(422, 'The provided data is invalid.', $exception->errors());
    });

    $exceptions->render(function (
        AuthenticationException $exception,
        Request $request
    ) {
        if (!$request->is('api/*')) {
            return null;
        }

        return ApiErrorResponse::make(401, 'Authentication is required');
    });


    // Inventory operation rejected
    $exceptions->render(function (
        InventoryRejectedException $exception,
        Request $request
    ) {
        if (!$request->is('api/*')) {
            return null;
        }

        return ApiErrorResponse::make(409, $exception->getMessage());
    });

    // Inventory service unavailable
    $exceptions->render(function (
        InventoryServiceException $exception,
        Request $request
    ) {
        if (!$request->is('api/*')) {
            return null;
        }

        return ApiErrorResponse::make(503, 'The inventory service is temporarily unavailable.');
    });

    $exceptions->render(function (
        HttpExceptionInterface $exception,
        Request $request
    ) {
        if (!$request->is('api/*')) {
            return null;
        }

        $status = $exception->getStatusCode();

        $messages = [
            403 => 'You are not authorized to perform this action.',
            404 => 'The requested resource was not found.',
            405 => 'The requested HTTP method is not allowed.',
            429 => 'Too many requests. Please try again later.',
        ];

        return ApiErrorResponse::make(
            $status,
            $messages[$status] ?? 'Request failed',
            headers: $exception->getHeaders(),
        );
    });

    $exceptions->render(function (\Throwable $exception, Request $request) {
        if (!$request->is('api/*')) {
            return null;
        }

        \Illuminate\Support\Facades\Log::error(
            'API Unhandled Exception',
            [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]
        );

        return ApiErrorResponse::make(500, 'An unexpected server error occurred.');
    });
})
->create();
