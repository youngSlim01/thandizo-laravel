<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\District;
use App\Models\Province;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Só para desenvolvimento local: um administrador para conseguir entrar.
    // Os dados reais chegam pela migração a partir do Supabase.
    public function run(): void
    {
        // Os 4 distritos com que o programa começou (20261008113800).
        $programmeDistricts = [['Tete', 'Mutarara'], ['Manica', 'Guro'], ['Gaza', 'Mapai'], ['Gaza', 'Mabalane']];
        foreach ($programmeDistricts as [$provinceName, $districtName]) {
            $province = Province::firstOrCreate(['name' => $provinceName]);
            District::updateOrCreate(
                ['province_id' => $province->id, 'name' => $districtName],
                ['programme_active' => true],
            );
        }

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
