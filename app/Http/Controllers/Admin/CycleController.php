<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CyclePlanService;
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

    public function index(Request $request, CyclePlanService $plans): Response
    {
        $cycles = $this->cycles->list($request->user());
        $open = collect($cycles)->firstWhere('status', 'OPEN');

        return Inertia::render('admin/cycles', [
            'cycles' => $cycles,
            'canManage' => Gate::allows('meal-manager'),
            // Metas e feiras do ciclo aberto, por distrito (os dois perfis MEAL marcam).
            'plan' => $open ? $plans->forCycle($request->user(), $open['cycle_id']) : [],
        ]);
    }

    /**
     * Meta de agregados e datas da feira de um distrito no ciclo aberto.
     */
    public function savePlan(Request $request, string $cycle, string $district, CyclePlanService $plans): RedirectResponse
    {
        $data = $request->validate([
            'target_households' => ['nullable'],
            'fair_starts_on' => ['nullable', 'date'],
            'fair_ends_on' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $plans->save($request->user(), $cycle, $district, $data);

        return back()->with('success', 'Planeamento do distrito guardado.');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('meal-manager');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
        ]);
        $cycle = $this->cycles->open($request->user(), $data['name'] ?? null, $data['starts_on'] ?? null, $data['ends_on'] ?? null, $data['description'] ?? null);

        return back()->with('success', "Ciclo {$cycle['code']} aberto. Escolha agora os agregados a recarregar.");
    }

    /**
     * Nome, descrição e datas do ciclo.
     */
    public function update(Request $request, string $cycle): RedirectResponse
    {
        Gate::authorize('meal-manager');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
        ], [
            'name.max' => 'O nome do ciclo tem no máximo 100 caracteres.',
            'description.max' => 'A descrição tem no máximo 255 caracteres.',
        ]);
        $result = $this->cycles->update($request->user(), $cycle, $data);

        return back()->with('success', $result['changed'] ? 'Dados do ciclo guardados.' : 'Nada mudou.');
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
        $total = number_format((float) $result['total_amount'], 0, ',', ' ');

        return back()->with('success', "{$result['allocated']} agregado(s) recarregado(s), no total de {$total} MT, no mesmo cartão.");
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
