<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auto-login' => \App\Http\Middleware\AutoLoginDemoUser::class,
        ]);

        // Every page here is a logged-in view of live data - never cacheable
        // by a browser, CDN, or hosting-level page cache. See the class docblock.
        $middleware->web(append: [
            \App\Http\Middleware\DisableResponseCaching::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
