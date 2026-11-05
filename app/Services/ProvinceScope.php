<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Database\Query\Expression;

/**
 * Âmbito por província do Oficial MEAL (antigos private.is_province_scoped,
 * scope_province_id e in_scope_province): vê só a sua província; sem
 * província não vê nada. Os outros papéis vêem tudo.
 */
class ProvinceScope
{
    public function allows(User $user, ?string $provinceId): bool
    {
        return ! $user->isProvinceScoped()
            || ($provinceId !== null && $provinceId === $user->province_id);
    }

    /**
     * Limita uma consulta à província do Oficial MEAL.
     */
    public function apply(Builder $query, User $user, string|Expression $provinceColumn): Builder
    {
        if (! $user->isProvinceScoped()) {
            return $query;
        }

        return $user->province_id === null
            ? $query->whereRaw('1 = 0')
            : $query->where($provinceColumn, $user->province_id);
    }
}
