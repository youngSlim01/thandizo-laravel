<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Models\VoucherTier;
use App\Services\VoucherTierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Escalões do voucher, na Configuração (gestor MEAL e administrador).
 */
class VoucherTierController extends Controller
{
    public function __construct(private VoucherTierService $tiers) {}

    public function store(Request $request): RedirectResponse
    {
        $tier = $this->tiers->create($request->user(), $this->fields($request));

        return back()->with('success', "Escalão {$tier->name} criado.");
    }

    public function update(Request $request, VoucherTier $tier): RedirectResponse
    {
        $this->tiers->update($request->user(), $tier, $this->fields($request));

        return back()->with('success', 'Escalão guardado. O novo valor vale para as próximas ativações e recargas.');
    }

    public function updateStatus(Request $request, VoucherTier $tier): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(RecordStatus::class)]]);
        $status = RecordStatus::from($data['status']);
        $this->tiers->setStatus($request->user(), $tier, $status);

        return back()->with('success', $status === RecordStatus::Active ? 'Escalão reativado.' : 'Escalão desativado.');
    }

    public function makeDefault(Request $request, VoucherTier $tier): RedirectResponse
    {
        $this->tiers->makeDefault($request->user(), $tier);

        return back()->with('success', "{$tier->name} é agora o escalão padrão.");
    }

    /**
     * @return array{name: string|null, amount: mixed, description: string|null}
     */
    private function fields(Request $request): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'amount' => ['nullable'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'name.max' => 'O nome do escalão tem no máximo 80 caracteres.',
            'description.max' => 'A descrição tem no máximo 255 caracteres.',
        ]);

        return ['name' => $data['name'] ?? null, 'amount' => $data['amount'] ?? null, 'description' => $data['description'] ?? null];
    }
}
