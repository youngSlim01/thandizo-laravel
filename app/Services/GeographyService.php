<?php

namespace App\Services;

use App\Models\AdministrativePost;
use App\Models\Community;
use App\Models\District;
use App\Models\Locality;
use App\Models\Province;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Localização administrativa: província → distrito → posto administrativo →
 * localidade → comunidade (bairro).
 *
 * Substitui o bloco "COMMIT: geography first" de import_households
 * (20261008114600) e será usado pela importação e pelo registo de agregados
 * (nível 6).
 */
class GeographyService
{
    /**
     * Encontra a comunidade pela cadeia de nomes, criando os níveis em falta.
     *
     * Os nomes são normalizados como no sistema antigo (espaços nas pontas
     * retirados, espaços repetidos juntos). Maiúsculas e acentos ficam com a
     * collation do MySQL: "bairro   delta" e "Bairro Délta" são o mesmo bairro.
     * Um distrito criado aqui não fica no programa (programme_active = false).
     *
     * @param  array{province: string, district: string, administrative_post: string, locality: string, community: string}  $names
     *
     * @throws InvalidArgumentException quando falta algum dos cinco nomes
     */
    public function resolveCommunity(array $names): Community
    {
        $names = $this->normalize($names);

        return DB::transaction(function () use ($names): Community {
            $province = Province::query()->firstOrCreate(['name' => $names['province']]);
            $district = District::query()->firstOrCreate(['province_id' => $province->id, 'name' => $names['district']]);
            $post = AdministrativePost::query()->firstOrCreate(['district_id' => $district->id, 'name' => $names['administrative_post']]);
            $locality = Locality::query()->firstOrCreate(['administrative_post_id' => $post->id, 'name' => $names['locality']]);

            return Community::query()->firstOrCreate(
                ['locality_id' => $locality->id, 'name' => $names['community']],
                ['district_id' => $district->id],
            );
        });
    }

    /**
     * Normaliza um nome de lugar; devolve null quando fica vazio.
     */
    public function normalizeName(?string $name): ?string
    {
        $name = Str::squish((string) $name);

        return $name === '' ? null : $name;
    }

    /**
     * @param  array<string, string|null>  $names
     * @return array{province: string, district: string, administrative_post: string, locality: string, community: string}
     */
    private function normalize(array $names): array
    {
        $normalized = [];
        foreach (['province', 'district', 'administrative_post', 'locality', 'community'] as $level) {
            $normalized[$level] = $this->normalizeName($names[$level] ?? null)
                ?? throw new InvalidArgumentException("Falta o nome de {$level}.");
        }

        return $normalized;
    }
}
