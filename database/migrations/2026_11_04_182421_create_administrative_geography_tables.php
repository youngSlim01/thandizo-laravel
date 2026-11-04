<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nível 1: postos administrativos, localidades e comunidades (bairros).
     *
     * Equivalente a public.administrative_posts, public.localities e
     * public.communities (0001_initial_schema + 20261007195344).
     *
     * No PostgreSQL as comunidades tinham dois índices únicos parciais:
     * (locality_id, name) para as que têm localidade e (district_id, name)
     * para as antigas sem localidade. O MySQL não tem índices parciais:
     * - (locality_id, name) já ignora as linhas sem localidade, porque o MySQL
     *   trata NULL como diferente de NULL num índice único;
     * - para as antigas, a coluna gerada legacy_district_id só tem valor
     *   quando locality_id é NULL, e o índice (legacy_district_id, name) faz o
     *   mesmo papel do índice parcial.
     */
    public function up(): void
    {
        Schema::create('administrative_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('district_id')->constrained();
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['district_id', 'name']);
        });

        Schema::create('localities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('administrative_post_id')->constrained();
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['administrative_post_id', 'name']);
        });

        Schema::create('communities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('district_id')->constrained();
            $table->foreignUuid('locality_id')->nullable()->constrained();
            $table->string('name');
            $table->string('code')->nullable();
            $table->uuid('legacy_district_id')->nullable()
                ->storedAs('if(locality_id is null, district_id, null)');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['locality_id', 'name']);
            $table->unique(['legacy_district_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communities');
        Schema::dropIfExists('localities');
        Schema::dropIfExists('administrative_posts');
    }
};
