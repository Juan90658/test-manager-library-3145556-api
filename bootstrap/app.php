<?php

use App\Adapters\Inbound\Http\AuthenticateToken;
use App\Adapters\Inbound\Http\ErrorBody;
use App\Adapters\Inbound\Http\RequireRole;
use App\Domain\DomainError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['token' => AuthenticateToken::class, 'role' => RequireRole::class]);
        // No sessions or cookies: the API is stateless (JWT).
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // No secrets in logs (art. IX): request input is never attached to reports.
        $exceptions->dontFlash(['password', 'password_hash', 'current_password', 'password_confirmation']);
        $exceptions->dontReport([DomainError::class]);

        // A SINGLE error body for EVERY error (api-contract §1, RNF-4). No trace, SQL or path ever reaches the client.
        $exceptions->render(function (\Throwable $e, Request $request) {
            [$status, $code, $message, $details] = match (true) {
                $e instanceof DomainError => [
                    match ($e->errorCode()) {
                        'INVALID_CREDENTIALS' => 401,
                        'NOT_FOUND' => 404,
                        'MEMBER_LIMIT_EXCEEDED', 'DUPLICATE_BOOK_IN_LOAN', 'VALIDATION_ERROR', 'INVALID_DATE_RANGE' => 422,
                        default => 409,
                    },
                    $e->errorCode(), $e->getMessage(), $e->details,
                ],
                $e instanceof ValidationException => [422, 'VALIDATION_ERROR', 'Hay campos inválidos.',
                    collect($e->errors())->flatMap(fn ($msgs, $field) => array_map(fn ($m) => ['field' => $field, 'issue' => $m], $msgs))->values()->all()],
                $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', 'El recurso no existe.', []],
                $e instanceof MethodNotAllowedHttpException => [405, 'METHOD_NOT_ALLOWED', 'Método no permitido para esta ruta.', []],
                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 429 => [429, 'TOO_MANY_REQUESTS', 'Demasiadas peticiones.', []],
                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 413 => [422, 'VALIDATION_ERROR', 'El cuerpo de la petición es demasiado grande.', [['field' => 'file', 'issue' => 'máximo 2 MB']]],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'HTTP_ERROR', 'La petición no se pudo atender.', []],
                default => [500, 'INTERNAL_ERROR', 'Ocurrió un error inesperado.', []],
            };

            return ErrorBody::json($status, $code, $message, $details);
        });
    })->create();
