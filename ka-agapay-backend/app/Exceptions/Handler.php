<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        });

        $this->renderable(function (AuthenticationException $e) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });

        $this->renderable(function (ModelNotFoundException $e) {
            $model = class_basename($e->getModel());
            return response()->json(['message' => "{$model} not found."], 404);
        });

        $this->renderable(function (NotFoundHttpException $e) {
            return response()->json(['message' => 'The requested endpoint does not exist.'], 404);
        });

        $this->renderable(function (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
