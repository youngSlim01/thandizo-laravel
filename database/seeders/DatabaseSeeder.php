<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Só para desenvolvimento local: um administrador para conseguir entrar.
    // Os dados reais chegam pela migração a partir do Supabase.
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@thandizo.test'],
            [
                'full_name' => 'Administrador Local',
                'password' => 'password',
                'role' => UserRole::SystemAdmin,
            ],
        );
    }
}
