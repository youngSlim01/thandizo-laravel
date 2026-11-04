<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['full_name', 'email', 'password', 'role', 'status', 'province_id', 'agrodealer_id', 'must_change_password'])]
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
        ];
    }

    public function isActive(): bool
    {
        return $this->status === RecordStatus::Active;
    }
}
