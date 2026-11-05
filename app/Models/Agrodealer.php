<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Database\Factories\AgrodealerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fornecedor de insumos. O código (AGD-<DIS>-NNN) é gerado ao criar e nunca
 * muda; ver AgrodealerService.
 */
#[Fillable(['code', 'name', 'province_id', 'district_id', 'address', 'contact_phone', 'status'])]
class Agrodealer extends Model
{
    /** @use HasFactory<AgrodealerFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
        ];
    }

    /**
     * @return BelongsTo<District, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /**
     * @return HasMany<AgrodealerPos, $this>
     */
    public function pos(): HasMany
    {
        return $this->hasMany(AgrodealerPos::class);
    }

    /**
     * Produtos que o agrodealer vende (o preço vem do distrito).
     *
     * @return BelongsToMany<Product, $this>
     */
    public function catalogue(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'agrodealer_products');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Agrodealers que o utilizador pode ver: um Oficial MEAL só vê os da sua
     * província, e nenhum se não tiver província (antigo
     * private.in_scope_agrodealer).
     *
     * @param  Builder<Agrodealer>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->isProvinceScoped()) {
            return;
        }

        if ($user->province_id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        // Província do agrodealer, ou a do seu distrito nos registos antigos sem província.
        $query->where(fn (Builder $query) => $query
            ->where('province_id', $user->province_id)
            ->orWhere(fn (Builder $query) => $query
                ->whereNull('province_id')
                ->whereHas('district', fn (Builder $district) => $district->where('province_id', $user->province_id))));
    }
}
