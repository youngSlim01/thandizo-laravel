<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ClientProfileService;
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
        // Gestor MEAL e administrador: cancelar lotes, substituir cartões, gerir ciclos.
        Gate::define('meal-manager', fn (User $user): bool => $user->isActive() && $user->role->canManageHouseholds());
        // Ver as operações de todo o programa: back office MEAL e Finanças.
        Gate::define('view-operations', fn (User $user): bool => $user->isActive() && $user->role->canViewAllOperations());
        // Configuração da instalação (cliente, logótipos): só o administrador do sistema.
        Gate::define('configure-installation', fn (User $user): bool => $user->isActive() && $user->role === UserRole::SystemAdmin);
        Gate::define('finance', fn (User $user): bool => $user->isActive() && $user->role->isFinance());
        Gate::define('pos', fn (User $user): bool => $user->isActive() && $user->role->isSeller() && $user->agrodealer_id !== null);

        ResetPassword::toMailUsing(function (User $user, string $token): MailMessage {
            $minutes = config('auth.passwords.users.expire');
            $client = app(ClientProfileService::class)->current()['client'];
            $signature = $client ? "Thandizo · {$client}" : 'Thandizo';

            return (new MailMessage)
                ->subject('Thandizo — recuperar a palavra-passe')
                ->greeting("Olá, {$user->full_name}")
                ->line('Recebemos um pedido para escolher uma nova palavra-passe para a sua conta Thandizo.')
                ->action('Escolher nova palavra-passe', route('password.reset', ['token' => $token, 'email' => $user->email]))
                ->line("Este link é válido durante {$minutes} minutos.")
                ->line('Se não fez este pedido, ignore este email: a sua palavra-passe não muda.')
                ->salutation($signature);
        });
    }
}
