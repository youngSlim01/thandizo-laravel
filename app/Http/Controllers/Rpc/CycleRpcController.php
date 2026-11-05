<?php

namespace App\Http\Controllers\Rpc;

use App\Services\CycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ciclos (nível 8). Ver: papéis centrais. Abrir, recarregar e fechar:
 * gestor MEAL e administrador.
 */
class CycleRpcController extends RpcController
{
    public function __construct(private CycleService $cycles) {}

    private function requireManager(Request $request): void
    {
        $user = $this->user($request);
        $this->requireRole($user->isActive() && $user->role->canManageHouseholds(), 'CYCLE_NOT_ALLOWED');
    }

    private function requireViewer(Request $request): void
    {
        $user = $this->user($request);
        $this->requireRole($user->isActive() && $user->role->canViewAllOperations(), 'CYCLE_NOT_ALLOWED');
    }

    public function listProgrammeCycles(Request $request): JsonResponse
    {
        $this->requireViewer($request);

        return response()->json($this->cycles->list($this->user($request)));
    }

    public function cycleSummary(Request $request): JsonResponse
    {
        $this->requireViewer($request);

        return response()->json($this->cycles->summary($this->user($request), $request->input('cycle_id')));
    }

    public function openProgrammeCycle(Request $request): JsonResponse
    {
        $this->requireManager($request);

        return response()->json($this->cycles->open($this->user($request), $request->input('name'), $request->input('starts_on'), $request->input('ends_on')));
    }

    public function allocateCycle(Request $request): JsonResponse
    {
        $this->requireManager($request);

        return response()->json($this->cycles->allocate(
            $this->user($request), $request->input('cycle_id'), $this->list($request, 'household_ids'), $this->list($request, 'household_codes'), $request->boolean('commit'),
        ));
    }

    public function closeProgrammeCycle(Request $request): JsonResponse
    {
        $this->requireManager($request);

        return response()->json($this->cycles->close($this->user($request), $request->input('cycle_id'), $request->boolean('commit')));
    }
}
