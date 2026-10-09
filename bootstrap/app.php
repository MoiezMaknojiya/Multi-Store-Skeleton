<?php

use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\ChooseTheOnlyOrganization;
use App\Http\Middleware\EnsureOrganizationIsActive;
use App\Http\Middleware\RefuseAStaleTab;
use App\Http\Middleware\RejectMalformedText;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The device API. Deliberately NOT routes/api.php and NOT in the web
            // group: a TV has no session and no CSRF token, so these routes get
            // their own stateless stack. See routes/device.php.
            Route::prefix('device')->group(base_path('routes/device.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Before anything else, on every route — the device API's too: text that is not UTF-8 is refused
        // at the door rather than met by whatever would choke on it (a JSON answer, MySQL, a limiter's key).
        $middleware->prepend(RejectMalformedText::class);

        // Somebody with one organization works in it from the first request, so the page drawn says so (ChooseTheOnlyOrganization),
        // and then a page left open while the session changed in another tab never acts as the new session (RefuseAStaleTab).
        $middleware->appendToGroup('web', [ChooseTheOnlyOrganization::class, RefuseAStaleTab::class]);

        $middleware->alias([
            'device.token' => AuthenticateDevice::class,
            'organization.active' => EnsureOrganizationIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A page's own request always gets words it can show (QA round, 2026-10-09). Before this, a bare abort(404) answered
        // `"message": ""`, and every page that toasts `message ?? '…'` showed an empty red box. A missing model said "No query
        // results for model [App\Models\…]" in production. A server error said "Server Error".
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            // A page refused only because somebody with several organizations has not chosen one yet goes to the chooser,
            // which returns here once one is chosen (OrganizationController::switch) — never "Your role does not allow it".
            $user = $request->user();
            if ($response->getStatusCode() === 403 && ! $request->expectsJson() && $request->isMethod('GET') && $user !== null
                && $request->hasSession() && ! $request->session()->get('current_organization_id') && $user->globalRole() === null
                && $user->organizations()->count() > 1) {
                return redirect()->guest(route('organizations.select'));
            }

            if (! $request->expectsJson() || ! $response instanceof JsonResponse) {
                return $response;
            }

            $status = $response->getStatusCode();
            $data = $response->getData(true);
            $message = is_array($data) ? (string) ($data['message'] ?? '') : '';

            $plain = match (true) {
                // The words the shared handler says before it reloads (core/bootstrap.js), so the two make one toast.
                in_array($status, [401, 419], true) => 'Your session has ended. Sign in again to carry on.',
                $status === 404 && ($message === '' || str_starts_with($message, 'No query results') || str_starts_with($message, 'The route ')) => 'This is no longer here: it may have been deleted, or changed in another tab. Reload the page to see what is there now.',
                $status === 403 && $message === 'This action is unauthorized.' => 'You are not allowed to do this. If your role was changed, reload the page.',
                $status === 405 => 'That cannot be done here. Reload the page and try again.',
                $status >= 500 && ! config('app.debug') => 'Something went wrong on our side. Nothing was changed. Please try again in a moment.',
                default => null,
            };

            if ($plain === null) {
                return $response;
            }

            $data = is_array($data) ? $data : [];
            $data['message'] = $plain;

            return $response->setData($data);
        });
    })->create();
