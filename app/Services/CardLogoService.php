<?php

namespace App\Services;

use App\Models\CardLogo;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Logótipos do cliente nos cartões: no máximo três, da esquerda para a
 * direita. Carregados pelo administrador no ecrã de configuração.
 */
class CardLogoService
{
    public const MAX_LOGOS = 3;

    /**
     * Logótipos por ordem, sem o conteúdo.
     *
     * @return Collection<int, CardLogo>
     */
    public function list(): Collection
    {
        return CardLogo::query()->orderBy('position')->get(['id', 'position', 'original_name', 'mime_type', 'updated_at']);
    }

    /**
     * Junta um logótipo à direita dos que já existem.
     */
    public function add(User $actor, UploadedFile $file): CardLogo
    {
        return DB::transaction(function () use ($actor, $file): CardLogo {
            $positions = CardLogo::query()->lockForUpdate()->pluck('position');
            if ($positions->count() >= self::MAX_LOGOS) {
                throw new InvalidArgumentException('O cartão leva no máximo 3 logótipos. Retire um antes de carregar outro.');
            }

            $logo = CardLogo::query()->create([
                'position' => ($positions->max() ?? 0) + 1,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $file->getMimeType(),
                'content' => base64_encode((string) file_get_contents($file->getRealPath())),
                'uploaded_by' => $actor->id,
            ]);
            Audit::log($actor->id, 'CARD_LOGO_ADDED', 'CARD_LOGO', $logo->id, ['position' => $logo->position, 'name' => $logo->original_name]);

            return $logo;
        });
    }

    /**
     * Troca o logótipo com o que está à sua esquerda.
     */
    public function moveLeft(User $actor, CardLogo $logo): void
    {
        DB::transaction(function () use ($actor, $logo): void {
            $left = CardLogo::query()->where('position', '<', $logo->position)->orderByDesc('position')->lockForUpdate()->first();
            if ($left === null) {
                return;
            }
            [$from, $to] = [$logo->position, $left->position];
            // A posição é única: passa por 0 para não colidir a meio da troca.
            $logo->update(['position' => 0]);
            $left->update(['position' => $from]);
            $logo->update(['position' => $to]);
            Audit::log($actor->id, 'CARD_LOGO_MOVED', 'CARD_LOGO', $logo->id, ['from' => $from, 'to' => $to]);
        });
    }

    /**
     * Retira um logótipo; os da direita avançam uma posição.
     */
    public function remove(User $actor, CardLogo $logo): void
    {
        DB::transaction(function () use ($actor, $logo): void {
            $logo->delete();
            CardLogo::query()->where('position', '>', $logo->position)->orderBy('position')->get()
                ->each(fn (CardLogo $next) => $next->update(['position' => $next->position - 1]));
            Audit::log($actor->id, 'CARD_LOGO_REMOVED', 'CARD_LOGO', $logo->id, ['name' => $logo->original_name]);
        });
    }
}
