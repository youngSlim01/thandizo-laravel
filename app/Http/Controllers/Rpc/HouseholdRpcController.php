<?php

namespace App\Http\Controllers\Rpc;

use App\Exceptions\DomainRuleException;
use App\Models\Household;
use App\Services\HouseholdSearchService;
use App\Services\HouseholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Pesquisa, geografia e ficha de agregados para os ecrãs de cartões, ciclos
 * e transacções (as mesmas regras da página de beneficiários). Ver: papéis
 * centrais, o Oficial MEAL na sua província.
 */
class HouseholdRpcController extends RpcController
{
    public function __construct(private HouseholdSearchService $search) {}

    private function requireViewer(Request $request): void
    {
        $user = $this->user($request);
        $this->requireRole($user->isActive() && $user->role->canViewAllOperations(), 'HOUSEHOLDS_NOT_ALLOWED');
    }

    public function searchHouseholds(Request $request): JsonResponse
    {
        $this->requireViewer($request);
        $filters = $request->input('filters');
        try {
            return response()->json($this->search->search(
                $this->user($request), is_array($filters) ? $filters : [], (int) $request->input('limit', 50),
                (int) $request->input('offset', 0), (string) $request->input('sort', 'code'),
            ));
        } catch (InvalidArgumentException $exception) {
            throw new DomainRuleException($exception->getMessage());
        }
    }

    public function listHouseholdGeography(Request $request): JsonResponse
    {
        $this->requireViewer($request);

        return response()->json($this->search->geography($this->user($request)));
    }

    public function getHouseholdDetail(Request $request, HouseholdService $households): JsonResponse
    {
        $this->requireViewer($request);
        $household = Household::query()->find($request->input('household_id'));
        if ($household === null || ! $this->search->isVisible($this->user($request), $household->id)) {
            throw new DomainRuleException('HOUSEHOLD_NOT_FOUND');
        }

        return response()->json($households->detail($household, $this->search, $this->user($request)));
    }
}
