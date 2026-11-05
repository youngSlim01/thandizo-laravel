<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ciclos do programa: um aberto de cada vez. O back office MEAL vê os
 * ciclos e o relatório; abrir, recarregar e fechar é do gestor MEAL e do
 * administrador.
 */
class CycleController extends Controller
{
    public function __construct(private CycleService $cycles) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/cycles', [
            'cycles' => $this->cycles->list($request->user()),
            'canManage' => Gate::allows('meal-manager'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('meal-manager');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
        ]);
        $cycle = $this->cycles->open($request->user(), $data['name'] ?? null, $data['starts_on'] ?? null, $data['ends_on'] ?? null);

        return back()->with('success', "Ciclo {$cycle['code']} aberto. Escolha agora os agregados a recarregar.");
    }

    /**
     * Relatório do ciclo (números, vendas e quem ainda tem saldo).
     */
    public function summary(Request $request, string $cycle): JsonResponse
    {
        return response()->json($this->cycles->summary($request->user(), $cycle));
    }

    /**
     * Quem recebe e quem fica de fora, sem recarregar.
     */
    public function previewAllocation(Request $request, string $cycle): JsonResponse
    {
        Gate::authorize('meal-manager');
        [$ids, $codes] = $this->selection($request);

        return response()->json($this->cycles->allocate($request->user(), $cycle, $ids, $codes, false));
    }

    public function allocate(Request $request, string $cycle): RedirectResponse
    {
        Gate::authorize('meal-manager');
        [$ids, $codes] = $this->selection($request);
        $result = $this->cycles->allocate($request->user(), $cycle, $ids, $codes, true);
        $amount = number_format((float) $result['amount_each'], 0, ',', ' ');

        return back()->with('success', "{$result['allocated']} agregado(s) recarregado(s) com {$amount} MT cada, no mesmo cartão.");
    }

    /**
     * Quem ainda tem saldo, que expira ao fechar.
     */
    public function previewClose(Request $request, string $cycle): JsonResponse
    {
        Gate::authorize('meal-manager');

        return response()->json($this->cycles->close($request->user(), $cycle, false));
    }

    public function close(Request $request, string $cycle): RedirectResponse
    {
        Gate::authorize('meal-manager');
        $result = $this->cycles->close($request->user(), $cycle, true);
        $unused = number_format((float) $result['unused_amount'], 0, ',', ' ');

        return back()->with('success', "Ciclo fechado. {$result['households_with_balance']} agregado(s) não usaram tudo: {$unused} MT expirou e fica no relatório como não utilizado.");
    }

    /**
     * @return array{0: list<string>|null, 1: list<string>|null}
     */
    private function selection(Request $request): array
    {
        $data = $request->validate([
            'household_ids' => ['nullable', 'array'],
            'household_ids.*' => ['string'],
            'household_codes' => ['nullable', 'array'],
            'household_codes.*' => ['string'],
        ]);

        return [
            isset($data['household_ids']) ? array_values($data['household_ids']) : null,
            isset($data['household_codes']) ? array_values($data['household_codes']) : null,
        ];
    }
}
