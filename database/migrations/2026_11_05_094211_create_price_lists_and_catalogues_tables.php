<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nível 5: preços por distrito e catálogos dos agrodealers.
     *
     * Equivalente a public.price_lists (0001 + 20261008113800) e
     * public.agrodealer_products. Um preço nunca se apaga: mudar fecha o
     * actual (effective_to) e abre outro, para as vendas guardarem o preço
     * que tinham.
     *
     * "No máximo um preço aberto por produto e distrito" era um índice único
     * parcial no PostgreSQL. Em MySQL é a coluna gerada open_price_key, que
     * só tem valor nos preços abertos, com um índice único.
     */
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained();
            $table->foreignUuid('district_id')->nullable()->constrained();
            $table->decimal('unit_price', 14, 2);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->string('open_price_key', 80)->nullable()->storedAs(
                "if(status = 'ACTIVE' and effective_to is null and district_id is not null, concat(product_id, ':', district_id), null)"
            );

            $table->unique('open_price_key');
            $table->index(['product_id', 'district_id', 'status', 'effective_from']);
        });

        DB::statement('alter table price_lists add constraint price_lists_unit_price_check check (unit_price >= 0)');
        DB::statement('alter table price_lists add constraint price_lists_period_check check (effective_to is null or effective_to > effective_from)');

        Schema::create('agrodealer_products', function (Blueprint $table) {
            $table->foreignUuid('agrodealer_id')->constrained();
            $table->foreignUuid('product_id')->constrained();
            $table->timestamp('created_at')->useCurrent();
            $table->foreignUuid('created_by')->nullable()->constrained('users');

            $table->primary(['agrodealer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agrodealer_products');
        Schema::dropIfExists('price_lists');
    }
};
