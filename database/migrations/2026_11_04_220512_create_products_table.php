<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nível 2: produtos (antigo public.products).
     *
     * O nome passa a ser único também na base de dados: no sistema antigo só
     * a função save_product o verificava. Pela collation, "Semente de milho"
     * e "semente de mílho" são o mesmo nome.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name')->unique();
            $table->string('category')->nullable();
            $table->string('unit', 50);
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamps();

            $table->index(['status', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
