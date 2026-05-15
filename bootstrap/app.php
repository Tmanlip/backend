<?php

use App\Http\Middleware\ApiErrorResponse;
use App\Http\Middleware\LogUserInteraction;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', [
            ApiErrorResponse::class,
            LogUserInteraction::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated',
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $status = 500;
            $message = 'Server Error';
            $payload = [
                'success' => false,
                'message' => $message,
                'status' => $status,
            ];

            if ($e instanceof ValidationException) {
                $status = 422;
                $message = 'Validation error';
                $payload['errors'] = $e->errors();
            } elseif ($e instanceof ModelNotFoundException) {
                $status = 404;
                $message = 'Resource not found';
            } elseif ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'HTTP error';
            } else {
                $message = config('app.debug') ? $e->getMessage() : 'Server Error';
            }

            $payload['message'] = $message;
            $payload['status'] = $status;

            if (config('app.debug')) {
                $payload['exception'] = class_basename($e);
            }

            return response()->json($payload, $status);
        });
    })->create();
