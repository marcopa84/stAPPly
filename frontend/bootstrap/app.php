<?php

use App\Http\Middleware\EndFinishedDemoSession;
use App\Http\Middleware\ProtectDemoAccount;
use App\Http\Middleware\RestrictImageSources;
use App\Http\Middleware\ThrottleRegistration;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EndFinishedDemoSession::class,
            ProtectDemoAccount::class,
            ThrottleRegistration::class,
            RestrictImageSources::class,
        ]);

        // Prima di auth: con la sandbox già cancellata dal prune, auth manderebbe al login
        // senza spiegare che la demo è finita.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EndFinishedDemoSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
