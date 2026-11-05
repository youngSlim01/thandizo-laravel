<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\AgrodealerPos;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;

/**
 * O POS em que o operador está a trabalhar, guardado na sessão (no sistema
 * antigo ficava no browser e também se perdia ao sair). Com um só POS
 * activo no agrodealer, é esse.
 */
class PosTerminal
{
    private const SESSION_KEY = 'pos_id';

    /**
     * POS activos do agrodealer de quem vende.
     *
     * @return Collection<int, AgrodealerPos>
     */
    public function available(User $user): Collection
    {
        if (! $user->role->isSeller() || $user->agrodealer_id === null) {
            return new Collection;
        }

        return AgrodealerPos::query()
            ->where('agrodealer_id', $user->agrodealer_id)
            ->where('status', RecordStatus::Active)
            ->orderBy('pos_code')
            ->get(['id', 'pos_code', 'device_label']);
    }

    public function current(User $user, Session $session): ?AgrodealerPos
    {
        $available = $this->available($user);
        $chosen = $available->firstWhere('id', $session->get(self::SESSION_KEY));

        return $chosen ?? ($available->count() === 1 ? $available->first() : null);
    }

    /**
     * Escolhe o POS; devolve falso quando não é um POS activo do agrodealer.
     */
    public function choose(User $user, Session $session, string $posId): bool
    {
        if ($this->available($user)->firstWhere('id', $posId) === null) {
            return false;
        }
        $session->put(self::SESSION_KEY, $posId);

        return true;
    }

    public function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
