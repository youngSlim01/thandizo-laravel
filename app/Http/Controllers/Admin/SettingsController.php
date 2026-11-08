<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CardLogo;
use App\Models\VoucherTier;
use App\Services\CardLogoService;
use App\Services\ClientProfileService;
use App\Services\VoucherTierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Configuração da instalação: o cliente (organização, programa, frase), os
 * logótipos dos cartões e os escalões do voucher (VoucherTierController). Gestor MEAL e administrador; o nome da organização
 * só o administrador muda (ClientProfileService).
 */
class SettingsController extends Controller
{
    public function __construct(private CardLogoService $logos, private ClientProfileService $profile) {}

    public function index(VoucherTierService $tiers): Response
    {
        return Inertia::render('admin/settings', [
            'client' => $this->profile->current(),
            'canEditOrganization' => Gate::allows('configure-installation'),
            'logos' => $this->logos->list()->map(fn (CardLogo $logo): array => [
                'id' => $logo->id,
                'position' => $logo->position,
                'name' => $logo->original_name,
                'url' => $logo->url(),
            ]),
            'maxLogos' => CardLogoService::MAX_LOGOS,
            'tiers' => $tiers->list()->map(fn (VoucherTier $tier): array => [
                'id' => $tier->id,
                'name' => $tier->name,
                'amount' => (float) $tier->amount,
                'description' => $tier->description,
                'is_default' => $tier->is_default,
                'requires_household_code' => $tier->requires_household_code,
                'status' => $tier->status->value,
                'households' => $tier->households_count,
            ]),
        ]);
    }

    public function updateClient(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'programme' => ['nullable', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:160'],
        ], [
            'name.max' => 'O nome da organização tem no máximo 120 caracteres.',
            'programme.max' => 'O nome do programa tem no máximo 120 caracteres.',
            'tagline.max' => 'A frase tem no máximo 160 caracteres.',
        ]);
        $this->profile->update($request->user(), $data);

        return back()->with('success', 'Dados do cliente guardados.');
    }

    public function storeLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024'],
        ], [
            'logo.required' => 'Escolha o ficheiro do logótipo.',
            'logo.mimetypes' => 'Use uma imagem PNG, JPG ou WebP.',
            'logo.max' => 'O logótipo não pode passar de 1 MB.',
        ]);
        try {
            $this->logos->add($request->user(), $request->file('logo'));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['logo' => $exception->getMessage()]);
        }

        return back()->with('success', 'Logótipo carregado. Os próximos cartões já o levam.');
    }

    public function moveLogo(Request $request, CardLogo $logo): RedirectResponse
    {
        $this->logos->moveLeft($request->user(), $logo);

        return back();
    }

    public function destroyLogo(Request $request, CardLogo $logo): RedirectResponse
    {
        $this->logos->remove($request->user(), $logo);

        return back()->with('success', 'Logótipo retirado.');
    }
}
