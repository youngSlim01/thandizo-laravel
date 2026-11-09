<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CycleDashboardService;
use App\Services\ProgrammeSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Painel MEAL de um ciclo (o aberto por omissão; o Oficial MEAL só vê a
 * sua província).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CycleDashboardService $dashboard, ProgrammeSetupService $setup): Response
    {
        $data = $request->validate(['cycle' => ['nullable', 'uuid']]);

        return Inertia::render('admin/dashboard', [
            'dashboard' => fn () => $dashboard->forViewer($request->user(), $data['cycle'] ?? null),
            // Primeiros passos: só para quem monta o programa (gestor MEAL e administrador).
            'setup' => fn () => Gate::allows('configure-programme') ? $setup->steps() : [],
        ]);
    }

    /**
     * Lista de quem ainda não comprou num distrito (CSV para o Excel), para a
     * mobilização durante a feira ou para seguir quem faltou.
     */
    public function missing(Request $request, CycleDashboardService $dashboard): StreamedResponse
    {
        $data = $request->validate(['cycle' => ['required', 'uuid'], 'district' => ['required', 'uuid']]);
        $rows = $dashboard->missing($request->user(), $data['cycle'], $data['district']);
        $district = $rows->first()?->district ?? 'distrito';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['CODIGO AF', 'REPRESENTANTE', 'SEXO', 'BAIRRO', 'ESCALAO', 'CARTAO'], ';');
            foreach ($rows as $m) {
                fputcsv($out, [$m->household_code, $m->representative_name, $m->representative_sex, $m->community, $m->tier, $m->voucher_code], ';');
            }
            fclose($out);
        }, 'por-comprar-'.Str::slug($district).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
