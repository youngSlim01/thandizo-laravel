<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nível 0 (províncias e distritos) e a tabela de auditoria.
     *
     * Equivalente a public.provinces, public.districts (com programme_active,
     * de 20261008113800) e public.audit_logs do Supabase. A auditoria entra já
     * porque a primeira regra migrada (adicionar distrito) escreve nela.
     */
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('code')->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('province_id')->constrained();
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('programme_active')->default(false)->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['province_id', 'name']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('users');
            $table->string('action');
            $table->string('entity_type');
            $table->uuid('entity_id')->nullable();
            $table->json('metadata');
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['entity_type', 'entity_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('province_id')->references('id')->on('provinces');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['province_id']);
        });
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('provinces');
    }
};
