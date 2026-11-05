<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
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
        // Áreas do sistema, por grupo de papéis (antigos AdminGate,
        // FinanceGate e OperatorGate do Next.js). Só contas activas.
        Gate::define('meal-back-office', fn (User $user): bool => $user->isActive() && $user->role->isMealBackOffice());
        Gate::define('finance', fn (User $user): bool => $user->isActive() && $user->role->isFinance());
        Gate::define('pos', fn (User $user): bool => $user->isActive() && $user->role->isSeller() && $user->agrodealer_id !== null);

        ResetPassword::toMailUsing(function (User $user, string $token): MailMessage {
            $minutes = config('auth.passwords.users.expire');

            return (new MailMessage)
                ->subject('THANDIZO — recuperar a palavra-passe')
                ->greeting("Olá, {$user->full_name}")
                ->line('Recebemos um pedido para escolher uma nova palavra-passe para a sua conta THANDIZO.')
                ->action('Escolher nova palavra-passe', route('password.reset', ['token' => $token, 'email' => $user->email]))
                ->line("Este link é válido durante {$minutes} minutos.")
                ->line('Se não fez este pedido, ignore este email: a sua palavra-passe não muda.')
                ->salutation('THANDIZO');
        });
    }
}
