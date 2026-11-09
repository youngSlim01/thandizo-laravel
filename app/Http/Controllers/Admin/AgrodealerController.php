<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgrodealerRequest;
use App\Http\Requests\UpdateAgrodealerRequest;
use App\Models\Agrodealer;
use App\Models\AgrodealerPos;
use App\Models\District;
use App\Services\AgrodealerService;
use App\Services\ProgrammeDistrictService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AgrodealerController extends Controller
{
    public function index(Request $request, AgrodealerService $agrodealers, ProgrammeDistrictService $districts): Response
    {
        Gate::authorize('viewAny', Agrodealer::class);

        return Inertia::render('admin/agrodealers', [
            'agrodealers' => $agrodealers->list($request->user())->map(fn (Agrodealer $agrodealer): array => [
                'id' => $agrodealer->id,
                'code' => $agrodealer->code,
                'name' => $agrodealer->name,
                'status' => $agrodealer->status->value,
                'district_id' => $agrodealer->district_id,
                'district' => $agrodealer->district?->name,
                'province' => $agrodealer->province?->name ?? $agrodealer->district?->province?->name,
                'address' => $agrodealer->address,
                'contact_phone' => $agrodealer->contact_phone,
                'users' => $agrodealer->active_users_count,
                'catalogue_count' => $agrodealer->catalogue_count,
                'pos' => $agrodealer->pos->map(fn (AgrodealerPos $pos): array => [
                    'id' => $pos->id,
                    'pos_code' => $pos->pos_code,
                    'device_label' => $pos->device_label,
                    'status' => $pos->status->value,
                    'last_seen_at' => $pos->last_seen_at?->toIso8601String(),
                ]),
            ]),
            // O oficial MEAL só escolhe distritos da sua província.
            'districts' => $districts->list()
                ->filter(fn (District $district): bool => ! $request->user()->isProvinceScoped() || $district->province_id === $request->user()->province_id)
                ->values()->map(fn (District $district): array => [
                    'id' => $district->id,
                    'name' => $district->name,
                    'province' => $district->province->name,
                ]),
            'canManage' => $request->user()->can('create', Agrodealer::class),
            // O catálogo é só do gestor MEAL e do administrador.
            'canManageCatalogue' => $request->user()->role->canManageCatalogue(),
        ]);
    }

    public function store(StoreAgrodealerRequest $request, AgrodealerService $agrodealers): RedirectResponse
    {
        $agrodealer = $agrodealers->create($request->user(), $request->validated());

        return to_route('admin.agrodealers.index')
            ->with('success', "Comerciante {$agrodealer->name} ({$agrodealer->code}) criado, com o terminal {$agrodealer->pos()->value('pos_code')}. Falta o catálogo e a conta do gestor.");
    }

    public function update(UpdateAgrodealerRequest $request, Agrodealer $agrodealer, AgrodealerService $agrodealers): RedirectResponse
    {
        $agrodealers->update($request->user(), $agrodealer, $request->validated());

        return to_route('admin.agrodealers.index')
            ->with('success', "Comerciante {$agrodealer->name} atualizado.");
    }
}
