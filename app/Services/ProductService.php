<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Produtos (insumos agrícolas).
 *
 * Substitui save_product e private.next_product_code
 * (supabase/migrations/20261008114300_generated_codes.sql). Os preços por
 * distrito e o número de agrodealers por produto chegam nos níveis 3 e 5.
 */
class ProductService
{
    /**
     * Produtos activos primeiro, depois por nome.
     *
     * @return Collection<int, Product>
     */
    public function list(): Collection
    {
        return Product::query()
            ->orderBy('status')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cria um produto com o próximo código PRD-NNNN.
     *
     * @param  array{name: string, unit: string, category?: string|null}  $data
     */
    public function create(User $actor, array $data): Product
    {
        return DB::transaction(function () use ($actor, $data): Product {
            $product = Product::query()->create([
                'code' => $this->nextCode(),
                ...$this->clean($data),
                'status' => RecordStatus::Active,
            ]);

            $this->audit($actor, 'PRODUCT_CREATED', $product);

            return $product;
        });
    }

    /**
     * Altera nome, unidade, categoria e situação. O código nunca muda.
     *
     * @param  array{name: string, unit: string, category?: string|null, status: RecordStatus|string}  $data
     */
    public function update(User $actor, Product $product, array $data): Product
    {
        return DB::transaction(function () use ($actor, $product, $data): Product {
            $product->update([
                ...$this->clean($data),
                'status' => RecordStatus::from($data['status'] instanceof RecordStatus ? $data['status']->value : $data['status']),
            ]);

            $this->audit($actor, 'PRODUCT_UPDATED', $product);

            return $product;
        });
    }

    /**
     * Próximo código PRD-NNNN (maior número existente + 1).
     *
     * O "for update" bloqueia os códigos PRD existentes até ao fim da
     * transacção: dois produtos criados ao mesmo tempo não recebem o mesmo
     * código (no PostgreSQL era um pg_advisory_xact_lock).
     */
    private function nextCode(): string
    {
        $last = DB::table('products')
            ->whereRaw("code regexp '^PRD-[0-9]+$'")
            ->lockForUpdate()
            ->pluck('code')
            ->map(fn (string $code): int => (int) substr($code, 4))
            ->max() ?? 0;

        return 'PRD-'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, unit: string, category: string|null}
     */
    private function clean(array $data): array
    {
        $category = trim((string) ($data['category'] ?? ''));

        return [
            'name' => trim($data['name']),
            'unit' => trim($data['unit']),
            'category' => $category === '' ? null : $category,
        ];
    }

    private function audit(User $actor, string $action, Product $product): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'entity_type' => 'PRODUCT',
            'entity_id' => $product->id,
            'metadata' => [
                'code' => $product->code,
                'name' => $product->name,
                'unit' => $product->unit,
                'status' => $product->status->value,
            ],
        ]);
    }
}
