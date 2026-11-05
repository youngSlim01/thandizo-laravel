<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgrodealerPosRequest;
use App\Http\Requests\UpdateAgrodealerPosRequest;
use App\Models\Agrodealer;
use App\Models\AgrodealerPos;
use App\Services\AgrodealerService;
use Illuminate\Http\RedirectResponse;

class AgrodealerPosController extends Controller
{
    public function store(StoreAgrodealerPosRequest $request, Agrodealer $agrodealer, AgrodealerService $agrodealers): RedirectResponse
    {
        $pos = $agrodealers->createPos($request->user(), $agrodealer, $request->validated('device_label'));

        return to_route('admin.agrodealers.index')->with('success', "POS {$pos->pos_code} criado.");
    }

    /**
     * A rota usa scopeBindings: um POS de outro agrodealer dá 404 (antigo INVALID_POS).
     */
    public function update(UpdateAgrodealerPosRequest $request, Agrodealer $agrodealer, AgrodealerPos $pos, AgrodealerService $agrodealers): RedirectResponse
    {
        $agrodealers->updatePos($request->user(), $pos, $request->validated('device_label'), $request->validated('status'));

        return to_route('admin.agrodealers.index')->with('success', "POS {$pos->pos_code} atualizado.");
    }
}
