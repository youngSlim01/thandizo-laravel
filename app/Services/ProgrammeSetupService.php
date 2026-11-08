<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Primeiros passos para montar um programa, pela ordem em que dependem uns
 * dos outros. Cada passo fica feito sozinho quando os dados existem. Mostrado
 * no painel MEAL ao gestor MEAL e ao administrador enquanto falta algum.
 */
class ProgrammeSetupService
{
    /**
     * @return list<array{key: string, title: string, text: string, href: string, done: bool}>
     */
    public function steps(): array
    {
        $activeDealers = DB::table('agrodealers')->where('status', 'ACTIVE');

        return [
            [
                'key' => 'settings', 'href' => '/admin/settings', 'title' => 'Configurar o programa',
                'text' => 'Cliente e programa, escalões do voucher e prazo de validação das Finanças.',
                'done' => DB::table('client_profiles')->where(fn ($q) => $q->whereNotNull('name')->orWhereNotNull('programme'))->exists(),
            ],
            [
                'key' => 'products', 'href' => '/admin/products', 'title' => 'Produtos e preços',
                'text' => 'Os insumos que os comerciantes vendem, com o preço em cada distrito do programa.',
                'done' => DB::table('price_lists as pl')->join('products as p', 'p.id', '=', 'pl.product_id')
                    ->join('districts as d', 'd.id', '=', 'pl.district_id')
                    ->where('pl.status', 'ACTIVE')->where('p.status', 'ACTIVE')->where('d.programme_active', true)->exists(),
            ],
            [
                'key' => 'merchants', 'href' => '/admin/comerciantes', 'title' => 'Comerciantes',
                'text' => 'Pelo menos um comerciante activo, com catálogo e um terminal POS.',
                'done' => (clone $activeDealers)
                    ->whereExists(fn ($q) => $q->from('agrodealer_products as c')->whereColumn('c.agrodealer_id', 'agrodealers.id'))
                    ->whereExists(fn ($q) => $q->from('agrodealer_pos as pos')->whereColumn('pos.agrodealer_id', 'agrodealers.id')->where('pos.status', 'ACTIVE'))
                    ->exists(),
            ],
            [
                'key' => 'merchant_users', 'href' => '/admin/users', 'title' => 'Utilizadores dos comerciantes',
                'text' => 'Cada comerciante activo com pelo menos um operador ou gestor.',
                'done' => (clone $activeDealers)->exists() && ! (clone $activeDealers)
                    ->whereNotExists(fn ($q) => $q->from('users as u')->whereColumn('u.agrodealer_id', 'agrodealers.id')->where('u.status', 'ACTIVE'))
                    ->exists(),
            ],
            [
                'key' => 'households', 'href' => '/admin/households', 'title' => 'Beneficiários',
                'text' => 'Registar ou importar a lista de agregados, cada um no seu escalão.',
                'done' => DB::table('households')->where('status', 'ACTIVE')->exists(),
            ],
            [
                'key' => 'cycle', 'href' => '/admin/cycles', 'title' => 'Abrir o ciclo',
                'text' => 'Nome, âmbito e datas do ciclo de assistência.',
                'done' => DB::table('programme_cycles')->exists(),
            ],
            [
                'key' => 'cards', 'href' => '/admin/cards', 'title' => 'Gerar e distribuir os cartões',
                'text' => 'Um lote por bairro, impressão e entrega em mão: o cartão fica activo com o valor do escalão.',
                'done' => DB::table('vouchers')->whereNotNull('distributed_at')->exists(),
            ],
        ];
    }
}
