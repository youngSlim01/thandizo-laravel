<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os preços passam a ser de cada ciclo (decidido a 9/11/2026): o ciclo
     * novo começa sem preços e o POS usa os do ciclo aberto. "Um preço aberto
     * por produto e distrito" passa a ser por ciclo.
     *
     * Preços já gravados ficam no ciclo aberto, ou no mais recente.
     */
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropUnique(['open_price_key']);
        });
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropColumn('open_price_key');
        });
        Schema::table('price_lists', function (Blueprint $table) {
            $table->foreignUuid('cycle_id')->nullable()->after('id')->constrained('programme_cycles');
        });

        $cycleId = DB::table('programme_cycles')->orderByRaw("status = 'OPEN' desc")->orderByDesc('opened_at')->value('id');
        if ($cycleId !== null) {
            DB::table('price_lists')->update(['cycle_id' => $cycleId]);
        }

        Schema::table('price_lists', function (Blueprint $table) {
            $table->string('open_price_key', 120)->nullable()->storedAs(
                "if(status = 'ACTIVE' and effective_to is null and district_id is not null and cycle_id is not null, concat(cycle_id, ':', product_id, ':', district_id), null)"
            );
            $table->unique('open_price_key');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropUnique(['open_price_key']);
        });
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropColumn('open_price_key');
            $table->dropConstrainedForeignId('cycle_id');
        });
        Schema::table('price_lists', function (Blueprint $table) {
            $table->string('open_price_key', 80)->nullable()->storedAs(
                "if(status = 'ACTIVE' and effective_to is null and district_id is not null, concat(product_id, ':', district_id), null)"
            );
            $table->unique('open_price_key');
        });
    }
};
