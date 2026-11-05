<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainRuleException;
use App\Models\Household;
use App\Services\HouseholdSearchService;
use App\Services\HouseholdService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Transações do programa, para o MEAL (monitoria) e as Finanças: a página,
 * a pesquisa com totais e o agregado de cada venda.
 */
class TransactionController extends Controller
{
    public function index(string $area): Response
    {
        return Inertia::render('transactions', ['area' => $area]);
    }

    public function search(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'filters' => ['nullable', 'array'],
            'limit' => ['nullable', 'integer'],
            'offset' => ['nullable', 'integer'],
            'with_items' => ['nullable', 'boolean'],
        ]);

        return response()->json($reports->searchTransactions(
            $request->user(), $data['filters'] ?? [], $data['limit'] ?? null, $data['offset'] ?? null, (bool) ($data['with_items'] ?? false),
        ));
    }

    /**
     * Ficha do agregado de uma venda (o Oficial MEAL só os da sua província
     * ou que compraram lá).
     */
    public function household(Request $request, Household $household, HouseholdService $households, HouseholdSearchService $search): JsonResponse
    {
        if (! $search->isVisible($request->user(), $household->id)) {
            throw new DomainRuleException('HOUSEHOLD_NOT_FOUND');
        }

        return response()->json($households->detail($household, $search, $request->user()));
    }
}
