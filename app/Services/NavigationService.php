<?php

namespace App\Services;

use App\Models\Agrodealer;
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
        $sections = [
            ['href' => '/admin/agrodealers', 'label' => 'Agrodealers', 'icon' => '⌂', 'ability' => ['viewAny', Agrodealer::class]],
            ['href' => '/admin/products', 'label' => 'Produtos e preços', 'icon' => '◈', 'ability' => ['viewAny', Product::class]],
            ['href' => '/admin/users', 'label' => 'Utilizadores', 'icon' => '♧', 'ability' => ['viewAny', User::class]],
        ];

        return array_values(array_map(
            fn (array $section): array => ['href' => $section['href'], 'label' => $section['label'], 'icon' => $section['icon']],
            array_filter($sections, fn (array $section): bool => $user->can(...$section['ability'])),
        ));
    }
}
