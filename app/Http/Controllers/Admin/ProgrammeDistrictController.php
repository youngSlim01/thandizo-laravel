<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProgrammeDistrictRequest;
use App\Models\District;
use App\Services\ProgrammeDistrictService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProgrammeDistrictController extends Controller
{
    public function index(Request $request, ProgrammeDistrictService $districts): Response
    {
        Gate::authorize('viewAny', District::class);

        return Inertia::render('admin/districts', [
            'districts' => $districts->list()->map(fn (District $district): array => [
                'id' => $district->id,
                'name' => $district->name,
                'province' => $district->province->name,
            ]),
            'canCreate' => $request->user()->can('create', District::class),
        ]);
    }

    public function store(StoreProgrammeDistrictRequest $request, ProgrammeDistrictService $districts): RedirectResponse
    {
        $district = $districts->add(
            $request->user(),
            $request->validated('province'),
            $request->validated('district'),
        );

        return to_route('admin.districts.index')
            ->with('success', "Distrito {$district->name} adicionado ao programa.");
    }
}
