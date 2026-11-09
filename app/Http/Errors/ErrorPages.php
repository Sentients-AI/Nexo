<?php

namespace App\Http\Errors;

use App\Http\Localization\Locale;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Inertia\ExceptionResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renders HTTP errors as the Inertia page Errors/ErrorPage, translated and
 * in the request's locale. Registered with `$exceptions->respond()` in
 * bootstrap/app.php.
 *
 * The page receives only the status code, never the exception, so no
 * message, trace or path reaches the browser (NFR-SEC-07). With APP_DEBUG
 * on, a 500 keeps Laravel's debug page for developers.
 */
final class ErrorPages
{
    public const STATUSES = [403, 404, 419, 500, 503];

    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! in_array($status, self::STATUSES, true)
            || $response instanceof JsonResponse
            || ($status === 500 && config('app.debug'))) {
            return $response;
        }

        // A 404 with no matching route never ran the `locale:` middleware;
        // the URL prefix is authoritative, so take the locale from it.
        if (Locale::current() === Locale::fallback()) {
            app()->setLocale(Locale::fromPath($request->path())->value);
        }

        return (new ExceptionResponse($exception, $request, $response, app(Router::class), app(Kernel::class)))
            ->render('Errors/ErrorPage', ['status' => $status])
            ->usingMiddleware(HandleInertiaRequests::class)
            ->withSharedData()
            ->toResponse($request);
    }
}
