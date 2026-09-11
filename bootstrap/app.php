<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware): void {

        // Trust Railway proxy
        $middleware->trustProxies(at: '*');

        // Middleware aliases
        $middleware->alias([
            'admin'               => \App\Http\Middleware\AdminMiddleware::class,
            'investor'            => \App\Http\Middleware\InvestorMiddleware::class,
            'registration.stage'  => \App\Http\Middleware\RegistrationStageMiddleware::class,
            'check.account'       => \App\Http\Middleware\CheckAccountStatus::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })

    ->create();