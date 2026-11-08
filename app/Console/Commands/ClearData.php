<?php

namespace App\Console\Commands;

use App\Models\Agrodealer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomeça uma instalação de testes do zero, guardando só os utilizadores
 * (com as mesmas palavras-passe), as províncias e os distritos do programa.
 * Tudo o resto sai: beneficiários, cartões, vendas, ciclos, escalões,
 * comerciantes, produtos e configuração.
 *
 * O ledger e as vendas não se apagam linha a linha (triggers): a base é
 * recriada com as migrações e os utilizadores voltam a entrar. A ligação a
 * um comerciante perde-se (o comerciante deixa de existir); a província
 * mantém-se pelo nome. Nunca corre em produção.
 */
#[Signature('thandizo:clear-data {--force : Não pedir confirmação}')]
#[Description('Apaga todos os dados de teste e guarda só os utilizadores')]
class ClearData extends Command
{
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Este comando não corre em produção.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Apagar todos os dados (beneficiários, cartões, vendas, ciclos, comerciantes, produtos, configuração) e guardar só os utilizadores?')) {
            $this->line('Nada foi apagado.');

            return self::SUCCESS;
        }

        $users = DB::table('users')->get()->map(fn (object $user): array => (array) $user)->all();
        $provinceNames = DB::table('provinces')->pluck('name', 'id');

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        DB::transaction(function () use ($users, $provinceNames): void {
            // O seeder cria um comerciante e um administrador de exemplo: saem.
            foreach (Agrodealer::query()->get() as $agrodealer) {
                $agrodealer->pos()->delete();
                $agrodealer->delete();
            }
            User::query()->delete();

            $provinces = DB::table('provinces')->pluck('id', 'name');
            foreach ($users as $user) {
                $province = $user['province_id'] ? ($provinceNames[$user['province_id']] ?? null) : null;
                DB::table('users')->insert([
                    ...$user,
                    'agrodealer_id' => null,
                    'province_id' => $province ? ($provinces[$province] ?? null) : null,
                ]);
            }
        });

        $unlinked = collect($users)->whereNotNull('agrodealer_id')->count();
        $this->info(count($users).' utilizador(es) guardado(s). Os restantes dados foram apagados.');
        if ($unlinked > 0) {
            $this->warn("{$unlinked} utilizador(es) de comerciante ficaram sem comerciante: volte a associá-los em Utilizadores depois de criar os comerciantes.");
        }

        return self::SUCCESS;
    }
}
