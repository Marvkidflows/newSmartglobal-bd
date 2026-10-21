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
            'financial'           => \App\Http\Middleware\FinancialMiddleware::class,
            'marvflow'            => \App\Http\Middleware\MarvflowMiddleware::class,
            'marvflow.lead'       => \App\Http\Middleware\MarvflowLeadMiddleware::class,
            'investor'            => \App\Http\Middleware\InvestorMiddleware::class,
            'registration.stage'  => \App\Http\Middleware\RegistrationStageMiddleware::class,
            'check.account'       => \App\Http\Middleware\CheckAccountStatus::class,
        ]);

        // Role gates must run BEFORE implicit route-model binding. Custom
        // middleware is not in Laravel's default priority list, so
        // SubstituteBindings used to run first: a non-admin hitting
        // /api/admin/investments/{id}/... got 404 for a missing record and 403
        // for an existing one, which leaks which ids exist and lets a
        // wrong-role caller reach the lookup layer. Authorization (the
        // role gates) now precedes the lookup; 'can:' gates stay after binding
        // because they need the bound model.
        foreach ([
            \App\Http\Middleware\AdminMiddleware::class,
            \App\Http\Middleware\FinancialMiddleware::class,
            \App\Http\Middleware\MarvflowMiddleware::class,
            \App\Http\Middleware\MarvflowLeadMiddleware::class,
            \App\Http\Middleware\InvestorMiddleware::class,
        ] as $roleGate) {
            $middleware->prependToPriorityList(
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                $roleGate
            );
        }
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })

    ->create();