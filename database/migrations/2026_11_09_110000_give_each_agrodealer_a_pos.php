<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * O terminal POS passa a ser criado com o comerciante (decidido a
     * 9/11/2026). Os comerciantes que ainda não têm nenhum ganham o primeiro.
     */
    public function up(): void
    {
        DB::table('agrodealers')
            ->whereNotExists(fn ($q) => $q->from('agrodealer_pos')->whereColumn('agrodealer_pos.agrodealer_id', 'agrodealers.id'))
            ->get(['id', 'code'])
            ->each(fn (object $agrodealer) => DB::table('agrodealer_pos')->insert([
                'id' => (string) Str::uuid7(),
                'agrodealer_id' => $agrodealer->id,
                'pos_code' => 'POS-'.preg_replace('/^MER-/', '', Str::upper($agrodealer->code)).'-01',
                'status' => 'ACTIVE',
                'created_at' => now(),
            ]));
    }

    public function down(): void
    {
        // Os terminais criados ficam: podem já ter vendas.
    }
};
