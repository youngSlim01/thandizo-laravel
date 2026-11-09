<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\HouseholdImportBatch;
use App\Services\HouseholdImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class HouseholdImportController extends Controller
{
    /**
     * Valida a lista (commit=false) ou grava-a (commit=true, só sem erros).
     */
    public function store(Request $request, HouseholdImportService $import): JsonResponse
    {
        Gate::authorize('import', Household::class);
        $data = $request->validate([
            'rows' => ['present', 'array'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'commit' => ['required', 'boolean'],
            'tier_id' => ['nullable', 'string'],
        ]);

        try {
            return response()->json($import->import($request->user(), $data['rows'], $data['file_name'] ?? null, (bool) $data['commit'], $data['tier_id'] ?? null));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rows' => $exception->getMessage()]);
        }
    }

    /**
     * As 10 importações mais recentes.
     */
    public function batches(): JsonResponse
    {
        Gate::authorize('import', Household::class);

        return response()->json(HouseholdImportBatch::query()
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'file_name', 'row_count', 'created_households', 'existing_households', 'created_at']));
    }
}
