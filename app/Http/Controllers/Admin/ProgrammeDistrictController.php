<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProgrammeDistrictRequest;
use App\Services\ProgrammeDistrictService;
use Illuminate\Http\RedirectResponse;

class ProgrammeDistrictController extends Controller
{
    public function store(StoreProgrammeDistrictRequest $request, ProgrammeDistrictService $districts): RedirectResponse
    {
        $district = $districts->add(
            $request->user(),
            $request->validated('province'),
            $request->validated('district'),
        );

        return back()
            ->with('success', "Distrito {$district->name} adicionado ao programa.");
    }
}
