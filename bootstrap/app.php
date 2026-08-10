<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));
        $exceptions->render(function (ValidationException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        });
        $exceptions->render(function (AuthenticationException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        });
        $exceptions->render(function (HttpExceptionInterface $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['success' => false, 'message' => $e->getStatusCode() === 403 ? 'Forbidden.' : ($e->getStatusCode() === 404 ? 'Resource not found.' : 'Request failed.')], $e->getStatusCode());
        });
    })->create();
