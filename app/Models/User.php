<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['full_name', 'email', 'username', 'password', 'role', 'status', 'province_id', 'agrodealer_id', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => RecordStatus::class,
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Agrodealer, $this>
     */
    public function agrodealer(): BelongsTo
    {
        return $this->belongsTo(Agrodealer::class);
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function isActive(): bool
    {
        return $this->status === RecordStatus::Active;
    }

    /**
     * Oficial MEAL activo: tudo o que vê fica limitado à sua província
     * (antigo private.is_province_scoped).
     */
    public function isProvinceScoped(): bool
    {
        return $this->isActive() && $this->role === UserRole::MealOfficer;
    }
}
