<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

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
        VerifyEmail::toMailUsing(fn ($notifiable, string $url) => (new MailMessage)
            ->subject('Confirma tu email en Rutero')
            ->line('Pulsa el botón para confirmar que este email es tuyo. Así podrás recibir invitaciones de equipos.')
            ->action('Confirmar email', $url)
            ->line('Si no has creado una cuenta en Rutero, ignora este correo.'));
    }
}
