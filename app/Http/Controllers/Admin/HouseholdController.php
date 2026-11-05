<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Services\HouseholdSearchService;
use App\Services\HouseholdService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Beneficiários. A página é Inertia; a pesquisa, a ficha e as alterações
 * respondem em JSON, com a mesma forma das antigas funções SQL, porque o ecrã
 * pede-as à medida que se filtra, pesquisa e abre fichas.
 */
class HouseholdController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Household::class);

        return Inertia::render('admin/households', [
            'canManage' => $request->user()->can('create', Household::class),
        ]);
    }

    public function search(Request $request, HouseholdSearchService $search): JsonResponse
    {
        Gate::authorize('viewAny', Household::class);
        $data = $request->validate([
            'filters' => ['nullable', 'array'],
            'limit' => ['nullable', 'integer'],
            'offset' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string'],
        ]);

        return $this->respond(fn () => $search->search(
            $request->user(),
            $data['filters'] ?? [],
            (int) ($data['limit'] ?? 50),
            (int) ($data['offset'] ?? 0),
            $data['sort'] ?? 'code',
        ));
    }

    public function geography(Request $request, HouseholdSearchService $search): JsonResponse
    {
        Gate::authorize('viewAny', Household::class);

        return response()->json($search->geography($request->user()));
    }

    public function show(Request $request, Household $household, HouseholdService $households, HouseholdSearchService $search): JsonResponse
    {
        $this->authorizeView($household);

        return response()->json($households->detail($household, $search, $request->user()));
    }

    public function history(Household $household, HouseholdService $households): JsonResponse
    {
        $this->authorizeView($household);

        return response()->json($households->history($household));
    }

    public function store(Request $request, HouseholdService $households): JsonResponse
    {
        Gate::authorize('create', Household::class);
        $data = $request->validate([
            'household_code' => ['nullable', 'string', 'max:30'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'sex' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'string', 'max:20'],
            'relationship' => ['nullable', 'string', 'max:255'],
            'community_id' => ['nullable', 'string'],
        ]);

        return $this->respond(function () use ($request, $households, $data): array {
            $household = $households->create($request->user(), $data);

            return ['household_id' => $household->id, 'household_code' => $household->household_code];
        });
    }

    public function update(Request $request, Household $household, HouseholdService $households): JsonResponse
    {
        Gate::authorize('update', $household);
        $data = $request->validate([
            'changes' => ['present', 'array'],
            'changes.*' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->respond(fn () => $households->update($request->user(), $household, $data['changes'], (string) ($data['reason'] ?? '')));
    }

    public function updateStatus(Request $request, Household $household, HouseholdService $households): JsonResponse
    {
        Gate::authorize('update', $household);
        $data = $request->validate(
            ['status' => ['required', Rule::enum(RecordStatus::class)], 'reason' => ['nullable', 'string', 'max:500']],
            ['status.required' => 'Estado do agregado inválido.', 'status.enum' => 'Estado do agregado inválido.'],
        );

        return $this->respond(fn () => [
            'changed' => $households->setStatus($request->user(), $household, RecordStatus::from($data['status']), (string) ($data['reason'] ?? '')),
        ]);
    }

    public function destroy(Request $request, Household $household, HouseholdService $households): JsonResponse
    {
        Gate::authorize('delete', $household);
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $reason = (string) $request->input('reason', '');

        return $this->respond(function () use ($request, $households, $household, $reason): array {
            $households->delete($request->user(), $household, $reason);

            return ['deleted' => true];
        });
    }

    /**
     * Fora do âmbito (outra província) responde-se 404, para não revelar que
     * o agregado existe.
     */
    private function authorizeView(Household $household): void
    {
        Gate::authorize('viewAny', Household::class);
        abort_unless(Gate::allows('view', $household), 404, 'Agregado não encontrado.');
    }

    /**
     * Executa a regra e transforma o erro de negócio numa resposta 422 com a
     * mensagem para o utilizador.
     */
    private function respond(Closure $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['message' => $exception->getMessage()]);
        }
    }
}
