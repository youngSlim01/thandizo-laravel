<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Escalões do voucher: cada agregado pertence a um escalão (por
     * vulnerabilidade ou categoria do grupo) e recebe o valor desse escalão
     * em cada activação e recarga. O valor fixo do ciclo deixa de existir.
     * Os agregados que já existem passam para o escalão "Padrão" (6 000 MT).
     */
    public function up(): void
    {
        Schema::create('voucher_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('ACTIVE');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        DB::statement('alter table voucher_tiers add constraint voucher_tiers_amount_check check (amount > 0)');

        $defaultId = (string) Str::uuid7();
        DB::table('voucher_tiers')->insert([
            'id' => $defaultId, 'name' => 'Padrão', 'amount' => 6000, 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::table('households', function (Blueprint $table) {
            $table->foreignUuid('voucher_tier_id')->nullable()->after('community_id')->constrained('voucher_tiers');
        });
        DB::table('households')->update(['voucher_tier_id' => $defaultId]);
        Schema::table('households', function (Blueprint $table) {
            $table->uuid('voucher_tier_id')->nullable(false)->change();
        });

        // O escalão em que o agregado estava quando recebeu, para os relatórios.
        Schema::table('cycle_households', function (Blueprint $table) {
            $table->foreignUuid('voucher_tier_id')->nullable()->after('voucher_id')->constrained('voucher_tiers');
        });

        DB::statement('alter table programme_cycles drop check programme_cycles_amount_check');
        Schema::table('programme_cycles', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }

    public function down(): void
    {
        Schema::table('programme_cycles', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->default(6000)->after('name');
        });
        DB::statement('alter table programme_cycles add constraint programme_cycles_amount_check check (amount > 0)');
        Schema::table('cycle_households', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_tier_id');
        });
        Schema::table('households', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_tier_id');
        });
        Schema::dropIfExists('voucher_tiers');
    }
};
