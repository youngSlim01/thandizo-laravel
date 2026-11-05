<?php

namespace App\Http\Middleware;

use App\Models\AgrodealerPos;
use App\Services\NavigationService;
use App\Services\PosTerminal;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    // Dados disponíveis em todas as páginas React via usePage().props.
    // Substitui o app/auth-context.tsx do sistema Next.js.
    public function share(Request $request): array
    {
        $user = $request->user();
        $terminal = app(PosTerminal::class);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'province' => $user->province?->name,
                    'province_id' => $user->province_id,
                    'agrodealer_id' => $user->agrodealer_id,
                    'agrodealer_name' => $user->agrodealer?->name,
                    // POS activos do agrodealer e o escolhido, para quem vende.
                    'pos_list' => $terminal->available($user)
                        ->map(fn (AgrodealerPos $pos): array => ['id' => $pos->id, 'code' => $pos->pos_code, 'label' => $pos->device_label])
                        ->values()->all(),
                    'pos' => ($pos = $terminal->current($user, $request->session()))
                        ? ['id' => $pos->id, 'code' => $pos->pos_code, 'label' => $pos->device_label]
                        : null,
                ] : null,
            ],
            // Menu lateral do back office (vazio para quem não tem secções).
            'navigation' => fn () => $user ? app(NavigationService::class)->sectionsFor($user) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Palavra-passe temporária de uma conta criada ou reposta: mostrada uma só vez.
                'credentials' => fn () => $request->session()->get('credentials'),
            ],
        ];
    }
}
