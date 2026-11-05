<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'type' => EnsureUserType::class,          // type:staff | type:client | type:student
            'permission' => EnsurePermission::class,  // permission:appointments.approve  (any of: a|b)
            'password.changed' => EnsurePasswordChanged::class,
        ]);
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Expected refusals ("slot taken", "not enough stock") are answers, not errors — keep them out of the error log.
        $exceptions->dontReport(BusinessRuleException::class);

        // Every /api response is JSON, whatever the Accept header says.
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());

        $exceptions->render(fn (BusinessRuleException $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
            'errors' => (object) [],
            'details' => (object) $e->details,
        ], $e->status));

        $exceptions->render(function (ValidationException $e, Request $r) {
            if ($r->is('api/*')) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'validation_failed', 'errors' => $e->errors()], $e->status);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $r) {
            if ($r->is('api/*')) {
                $model = $e->getPrevious() instanceof ModelNotFoundException ? class_basename($e->getPrevious()->getModel()) : null;

                return response()->json(['message' => $model ? "{$model} not found." : 'Not found.', 'code' => 'not_found'], 404);
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) {
            if ($r->is('api/*')) {
                return response()->json(['message' => 'Please sign in again.', 'code' => 'unauthenticated'], 401);
            }
        });
        $exceptions->render(function (AccessDeniedHttpException|AuthorizationException $e, Request $r) {
            if ($r->is('api/*')) {
                return response()->json(['message' => $e->getMessage() ?: 'You don’t have permission to do that.', 'code' => 'forbidden'], 403);
            }
        });
    })->create();
