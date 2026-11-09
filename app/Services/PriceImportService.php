<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Importação da lista de preços dos comités (Excel lido no browser e enviado
 * como linhas). Substitui import_product_prices (20261008114300).
 *
 * Tudo ou nada: um erro numa linha desfaz a importação inteira.
 */
class PriceImportService
{
    public function __construct(
        private ProductService $products,
        private PriceService $prices,
    ) {}

    /**
     * Com a feira de um distrito a decorrer, mudar um preço lá pede o motivo
     * (o mesmo para toda a importação).
     *
     * @param  list<array{code?: string|null, name?: string|null, unit?: string|null, category?: string|null, prices?: array<string, float|int|string|null>}>  $rows
     * @return array{rows: int, products_created: int, prices_set: int, prices_unchanged: int, last_code: string|null}
     *
     * @throws ValidationException com a linha e o motivo, em "rows"
     */
    public function import(User $actor, array $rows, ?string $fileName, ?string $reason = null): array
    {
        $districts = District::query()->inProgramme()->get()->keyBy('id');

        return DB::transaction(function () use ($actor, $rows, $fileName, $districts, $reason): array {
            $summary = ['rows' => 0, 'products_created' => 0, 'prices_set' => 0, 'prices_unchanged' => 0, 'last_code' => null];

            foreach ($rows as $index => $row) {
                $line = $index + 1;
                $product = $this->productForRow($actor, $row, $line, $summary);

                foreach ($row['prices'] ?? [] as $districtId => $price) {
                    if ($price === null || $price === '') {
                        continue;
                    }
                    $district = $districts->get($districtId) ?? $this->fail($line, 'distrito fora do programa.');
                    if (! is_numeric($price)) {
                        $this->fail($line, 'preço inválido.');
                    }
                    try {
                        $result = $this->prices->setPrice($actor, $product, $district, (float) $price, $reason);
                    } catch (InvalidArgumentException $exception) {
                        $message = $exception->getMessage();
                        $this->fail($line, mb_strtolower(mb_substr($message, 0, 1)).mb_substr($message, 1));
                    }
                    $result['changed'] ? $summary['prices_set']++ : $summary['prices_unchanged']++;
                }

                $summary['rows']++;
                $summary['last_code'] = $product->code;
            }

            if ($fileName !== null) {
                AuditLog::query()->create([
                    'actor_id' => $actor->id,
                    'action' => 'PRICES_IMPORTED',
                    'entity_type' => 'PRODUCT',
                    'entity_id' => null,
                    'metadata' => ['file_name' => $fileName, 'reason' => trim((string) $reason) ?: null, ...collect($summary)->except('last_code')->all()],
                ]);
            }

            return $summary;
        });
    }

    /**
     * Produto existente pelo código, ou produto novo quando o código vem
     * vazio. Um código desconhecido é recusado (um erro de escrita não deve
     * criar um produto repetido).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $summary
     */
    private function productForRow(User $actor, array $row, int $line, array &$summary): Product
    {
        $code = mb_strtoupper(trim((string) ($row['code'] ?? '')));

        if ($code !== '') {
            $product = Product::query()->where('code', $code)->first()
                ?? $this->fail($line, "o código {$code} não existe. Para um produto novo deixe o CODIGO vazio.");
            if ($product->status !== RecordStatus::Active) {
                $this->fail($line, "o produto {$code} está inativo.");
            }

            return $product;
        }

        $name = trim((string) ($row['name'] ?? ''));
        $unit = trim((string) ($row['unit'] ?? ''));
        if ($name === '' || $unit === '') {
            $this->fail($line, 'produto novo sem CODIGO; indique PRODUTO e UNIDADE.');
        }
        if (Product::query()->where('name', $name)->exists()) {
            $this->fail($line, "já existe um produto chamado {$name}. Use o CODIGO dele.");
        }

        $summary['products_created']++;

        return $this->products->create($actor, ['name' => $name, 'unit' => $unit, 'category' => $row['category'] ?? null]);
    }

    private function fail(int $line, string $reason): never
    {
        throw ValidationException::withMessages(['rows' => "Linha {$line}: {$reason}"]);
    }
}
