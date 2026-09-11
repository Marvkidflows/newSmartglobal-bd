<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
  
public function boot(): void
{
    Mail::extend('brevo', function (array $config) {
        $factory = new BrevoTransportFactory();

        return $factory->create(new Dsn(
            'brevo+api',
            'default',
            $config['key'] ?? null
        ));
    });
}
}