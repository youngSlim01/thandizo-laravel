<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\Household;
use App\Models\Product;
use App\Models\User;

/**
 * Secções do back office que cada pessoa vê no menu lateral (antigo
 * ADMIN_SECTIONS do Next.js). Cada secção aparece só se a Policy da página
 * deixar entrar, por isso o menu e as permissões nunca divergem. As secções
 * entram aqui à medida que cada módulo é migrado.
 */
class NavigationService
{
    /**
     * @return list<array{href: string, label: string, icon: string}>
     */
    public function sectionsFor(User $user): array
    {
        // Finanças tem a sua área; os produtos e preços só para consulta.
        if ($user->role === UserRole::FinanceOfficer) {
            $sections = [
                ['href' => '/finance', 'label' => 'Painel', 'icon' => '▣', 'ability' => ['finance']],
                ['href' => '/finance/settlements', 'label' => 'Reconciliações', 'icon' => '▤', 'ability' => ['finance']],
                ['href' => '/finance/transactions', 'label' => 'Transações', 'icon' => '⇄', 'ability' => ['finance']],
                ['href' => '/finance/reports', 'label' => 'Relatórios', 'icon' => '◫', 'ability' => ['finance']],
                ['href' => '/admin/products', 'label' => 'Produtos e preços', 'icon' => '◈', 'ability' => ['viewAny', Product::class]],
            ];

            return $this->allowed($user, $sections);
        }

        $sections = [
            ['href' => '/admin/dashboard', 'label' => 'Painel MEAL', 'icon' => '▦', 'ability' => ['meal-back-office']],
            ['href' => '/admin/households', 'label' => 'Beneficiários', 'icon' => '♙', 'ability' => ['viewAny', Household::class]],
            ['href' => '/admin/cards', 'label' => 'Cartões e distribuição', 'icon' => '▣', 'ability' => ['meal-back-office']],
            ['href' => '/admin/cycles', 'label' => 'Ciclos', 'icon' => '◷', 'ability' => ['meal-back-office']],
            ['href' => '/admin/transactions', 'label' => 'Transações', 'icon' => '⇄', 'ability' => ['meal-back-office']],
            ['href' => '/admin/settlements', 'label' => 'Reconciliações', 'icon' => '☷', 'ability' => ['meal-back-office']],
            ['href' => '/admin/reports', 'label' => 'Relatórios', 'icon' => '◫', 'ability' => ['meal-back-office']],
            ['href' => '/admin/agrodealers', 'label' => 'Comerciantes', 'icon' => '⌂', 'ability' => ['viewAny', Agrodealer::class]],
            ['href' => '/admin/products', 'label' => 'Produtos e preços', 'icon' => '◈', 'ability' => ['viewAny', Product::class]],
            ['href' => '/admin/users', 'label' => 'Utilizadores', 'icon' => '♧', 'ability' => ['viewAny', User::class]],
            ['href' => '/finance/settlements', 'label' => 'Validação Finance', 'icon' => '▤', 'ability' => ['finance']],
            ['href' => '/admin/settings', 'label' => 'Configuração', 'icon' => '⚙', 'ability' => ['configure-programme']],
        ];

        return $this->allowed($user, $sections);
    }

    /**
     * @param  list<array{href: string, label: string, icon: string, ability: array<int, string>}>  $sections
     * @return list<array{href: string, label: string, icon: string}>
     */
    private function allowed(User $user, array $sections): array
    {
        return array_values(array_map(
            fn (array $section): array => ['href' => $section['href'], 'label' => $section['label'], 'icon' => $section['icon']],
            array_filter($sections, fn (array $section): bool => $user->can(...$section['ability'])),
        ));
    }
}
