<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Escrita no ledger dos vouchers, o histórico financeiro oficial. Cada
 * entrada é imutável (trigger na base de dados).
 *
 * Uma entrega de cartão (INITIAL_ALLOCATION) precisa de um ciclo aberto e
 * põe o agregado nesse ciclo (antigo trigger private.ledger_cycle_link).
 */
class LedgerService
{
    /**
     * @param  array{transaction_id?: string|null, reference_voucher_id?: string|null, notes?: string|null, created_by?: string|null, created_at?: mixed}  $extra
     */
    public function record(string $voucherId, string $entryType, float|string $amount, float|string $balanceAfter, array $extra = []): string
    {
        $id = (string) Str::uuid7();
        $createdAt = $extra['created_at'] ?? now();

        if ($entryType === 'INITIAL_ALLOCATION') {
            $cycleId = DB::table('programme_cycles')->where('status', 'OPEN')->value('id')
                ?? throw new DomainRuleException('NO_OPEN_CYCLE');
            $household = DB::table('vouchers as v')->join('households as h', 'h.id', '=', 'v.household_id')
                ->where('v.id', $voucherId)->first(['h.id', 'h.voucher_tier_id']);
            DB::table('cycle_households')->insertOrIgnore([
                'cycle_id' => $cycleId,
                'household_id' => $household->id,
                'voucher_id' => $voucherId,
                'voucher_tier_id' => $household->voucher_tier_id,
                'amount' => $amount,
                'allocated_at' => $createdAt,
                'allocated_by' => $extra['created_by'] ?? null,
                'source' => 'DELIVERY',
            ]);
        }

        DB::table('voucher_ledger')->insert([
            'id' => $id,
            'voucher_id' => $voucherId,
            'transaction_id' => $extra['transaction_id'] ?? null,
            'entry_type' => $entryType,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_voucher_id' => $extra['reference_voucher_id'] ?? null,
            'notes' => $extra['notes'] ?? null,
            'created_by' => $extra['created_by'] ?? null,
            'created_at' => $createdAt,
        ]);

        return $id;
    }
}
