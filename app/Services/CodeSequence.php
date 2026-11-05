<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Numeradores com nome (antigas sequences do PostgreSQL): números de série
 * dos cartões, dos lotes, das vendas…
 */
class CodeSequence
{
    /**
     * Próximo valor. Dentro de uma transacção, a linha fica bloqueada até ao
     * fim, por isso dois pedidos nunca recebem o mesmo número.
     */
    public function next(string $name): int
    {
        return DB::transaction(function () use ($name): int {
            DB::table('code_sequences')->insertOrIgnore(['name' => $name, 'value' => 0]);
            $value = (int) DB::table('code_sequences')->where('name', $name)->lockForUpdate()->value('value') + 1;
            DB::table('code_sequences')->where('name', $name)->update(['value' => $value]);

            return $value;
        });
    }
}
