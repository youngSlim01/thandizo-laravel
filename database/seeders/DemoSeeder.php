<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\AdministrativePost;
use App\Models\Agrodealer;
use App\Models\Community;
use App\Models\District;
use App\Models\Household;
use App\Models\Locality;
use App\Models\User;
use App\Services\CardLogoService;
use App\Services\CardService;
use App\Services\ClientProfileService;
use App\Services\CycleService;
use App\Services\PosService;
use App\Services\PriceService;
use App\Services\ProductService;
use App\Services\VoucherTierService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dados de demonstração para experimentar o sistema no browser:
 * utilizadores de cada papel (palavra-passe "password"), produtos com
 * preço, catálogo, ciclo aberto, 3 escalões, 20 agregados com cartão entregue e 5 vendas
 * do mês passado (para experimentar as reconciliações).
 *
 * php artisan db:seed --class=DemoSeeder (só desenvolvimento).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        if (User::query()->where('email', 'operador@thandizo.test')->exists()) {
            $this->command?->info('Os dados de demonstração já existem.');

            return;
        }

        $admin = User::query()->where('email', 'admin@thandizo.test')->firstOrFail();
        $district = District::query()->where('name', 'Mutarara')->firstOrFail();
        $agrodealer = Agrodealer::query()->where('code', 'MER-MUT-001')->firstOrFail();

        $users = [
            ['operador@thandizo.test', 'Operador POS', UserRole::AgrodealerOperator, ['agrodealer_id' => $agrodealer->id]],
            ['gestor-agro@thandizo.test', 'Gestor do Comerciante', UserRole::AgrodealerManager, ['agrodealer_id' => $agrodealer->id]],
            ['meal@thandizo.test', 'Oficial MEAL Tete', UserRole::MealOfficer, ['province_id' => $district->province_id]],
            ['gestor-meal@thandizo.test', 'Gestor MEAL', UserRole::MealManager, []],
            ['financas@thandizo.test', 'Oficial de Finanças', UserRole::FinanceOfficer, []],
        ];
        foreach ($users as [$email, $name, $role, $extra]) {
            User::query()->create(['email' => $email, 'username' => strstr($email, '@', true), 'full_name' => $name, 'password' => 'password', 'role' => $role, ...$extra]);
        }

        // Tudo começa no início do mês passado, para haver vendas já fechadas.
        Carbon::setTestNow(now()->subMonthNoOverflow()->startOfMonth()->setTime(8, 0));

        $prices = app(PriceService::class);
        foreach ([['Semente de milho', 'kg', 150], ['Fertilizante NPK', 'saco 50 kg', 2500], ['Enxada', 'unidade', 450]] as [$name, $unit, $price]) {
            $product = app(ProductService::class)->create($admin, ['name' => $name, 'unit' => $unit]);
            $prices->setPrice($admin, $product, $district, $price);
            $agrodealer->catalogue()->attach($product->id);
        }

        $post = AdministrativePost::query()->create(['district_id' => $district->id, 'name' => 'Charre']);
        $locality = Locality::query()->create(['administrative_post_id' => $post->id, 'name' => 'Charre-Sede']);
        $community = Community::query()->create(['locality_id' => $locality->id, 'district_id' => $district->id, 'name' => 'Nhacatundo']);
        Household::factory()->count(20)->inCommunity($community)->create();

        // Escalões: 6 agregados a 7 500 MT, 10 no padrão (6 000 MT) e 4 a 3 000 MT.
        $tiers = app(VoucherTierService::class);
        $tierA = $tiers->create($admin, ['name' => 'Grupo A — muito vulnerável', 'amount' => 7500, 'description' => 'Chefiados por mulheres ou com mais de 6 membros', 'requires_household_code' => false]);
        $tierC = $tiers->create($admin, ['name' => 'Grupo C — apoio parcial', 'amount' => 3000, 'description' => 'Com outra fonte de rendimento', 'requires_household_code' => false]);
        $codes = Household::query()->orderBy('household_code')->pluck('id');
        Household::query()->whereIn('id', $codes->take(6))->update(['voucher_tier_id' => $tierA->id]);
        Household::query()->whereIn('id', $codes->take(-4))->update(['voucher_tier_id' => $tierC->id]);

        app(CycleService::class)->open($admin, 'AA - Janela 1', now()->toDateString(), now()->addDays(75)->toDateString(), 'Acções antecipadas à seca, Out–Dez');

        $cards = app(CardService::class);
        $batch = $cards->generateBatch($admin, $community->id);
        $cards->markPrinted($admin, $batch['batch_id']);
        $voucherIds = DB::table('vouchers')->where('card_batch_id', $batch['batch_id'])->pluck('id')->all();
        $cards->distribute($admin, $batch['batch_id'], $voucherIds, []);

        $operator = User::query()->where('email', 'operador@thandizo.test')->firstOrFail();
        $pos = $agrodealer->pos()->firstOrFail();
        $seed = $agrodealer->catalogue()->orderBy('name')->firstOrFail();
        $sales = app(PosService::class);
        foreach (DB::table('vouchers')->orderBy('voucher_code')->limit(5)->pluck('voucher_code') as $day => $code) {
            Carbon::setTestNow(Carbon::getTestNow()->addDays(2)->setTime(10, 0));
            $items = [['product_id' => $seed->id, 'quantity' => $day + 2]];
            $preview = $sales->preview($operator, $code, $agrodealer->id, $pos->id, $operator->id, $items, null, 0);
            $sales->confirm($operator, "demo-{$code}", $code, $pos->id, $items, null, 0, $preview['total_amount'], $preview['voucher_amount']);
        }
        Carbon::setTestNow();

        // Cliente e logótipos de demonstração, como se fossem definidos na Configuração.
        app(ClientProfileService::class)->update($admin, ['name' => 'Save the Children', 'programme' => 'Projecto LINK GCF']);
        $logos = app(CardLogoService::class);
        foreach (glob(__DIR__.'/demo-logos/*') as $file) {
            $logos->add($admin, new UploadedFile($file, basename($file), null, null, true));
        }

        $this->command?->info('Pronto. Utilizadores: admin, operador, gestor-agro, meal, gestor-meal, financas @thandizo.test — palavra-passe "password".');
    }
}
