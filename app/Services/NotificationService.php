<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Notificações do sino, no canto superior direito do back office: o que está
 * à espera de quem está ligado. Só entra o que tem valor.
 */
class NotificationService
{
    /**
     * @return list<array{key: string, level: string, label: string, value: int, href: string}>
     */
    public function forUser(User $user): array
    {
        if (! $user->isActive()) {
            return [];
        }

        $items = [];
        // Comerciantes registados (muitas vezes pelo oficial MEAL) que só
        // vendem depois de o gestor MEAL confirmar o catálogo.
        if ($user->role->canManageCatalogue()) {
            $waiting = DB::table('agrodealers as a')->where('a.status', 'ACTIVE')
                ->whereNotExists(fn ($q) => $q->from('agrodealer_products as c')->whereColumn('c.agrodealer_id', 'a.id'))
                ->count();
            $items[] = ['key' => 'catalogue_waiting', 'level' => 'attention', 'label' => 'Comerciantes à espera do catálogo', 'value' => $waiting, 'href' => '/admin/comerciantes?filtro=sem-catalogo'];
        }

        return array_values(array_filter($items, fn (array $item): bool => $item['value'] > 0));
    }
}
