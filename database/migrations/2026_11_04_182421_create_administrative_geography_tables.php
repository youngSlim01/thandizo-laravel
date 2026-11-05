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
     * Toda a comunidade tem localidade (no sistema antigo podia faltar, mas
     * não há nenhuma assim e os dados não são migrados). O distrito fica
     * também na comunidade, para os filtros não precisarem de três joins.
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
            $table->foreignUuid('locality_id')->constrained();
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['locality_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communities');
        Schema::dropIfExists('localities');
        Schema::dropIfExists('administrative_posts');
    }
};
