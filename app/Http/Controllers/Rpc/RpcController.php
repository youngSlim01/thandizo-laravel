<?php

namespace App\Http\Controllers\Rpc;

use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Base dos endpoints /rpc/<nome>: um por cada antiga função SQL chamada
 * pelos ecrãs vindos do Next.js, com os mesmos parâmetros (sem o prefixo
 * p_) e a mesma resposta. A regra está sempre num Service.
 */
abstract class RpcController extends Controller
{
    /**
     * Recusa com o código antigo (ex.: CARD_BATCH_NOT_ALLOWED) quando a
     * pessoa não tem o papel.
     */
    protected function requireRole(bool $allowed, string $code): void
    {
        if (! $allowed) {
            throw new DomainRuleException($code);
        }
    }

    protected function user(Request $request): User
    {
        return $request->user();
    }

    /**
     * @return list<string>|null
     */
    protected function list(Request $request, string $key): ?array
    {
        $value = $request->input($key);

        return is_array($value) ? array_values(array_map('strval', array_filter($value, fn ($v) => $v !== null))) : null;
    }
}
