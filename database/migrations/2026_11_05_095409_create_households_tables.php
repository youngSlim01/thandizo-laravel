<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nível 6: agregados familiares (beneficiários) e lotes de importação.
     *
     * Equivalente a public.households (0001 + 20261007195344 +
     * 20261008113600) e public.household_import_batches. Os cinco níveis de
     * localização ficam copiados no agregado (como no sistema antigo); os
     * registos antigos podem ter só alguns, e a pesquisa completa-os pela
     * comunidade.
     */
    public function up(): void
    {
        Schema::create('household_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('file_name')->nullable();
            $table->foreignUuid('imported_by')->nullable()->constrained('users');
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('created_households');
            $table->unsignedInteger('existing_households');
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('households', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('household_code', 20)->unique();
            $table->string('representative_name');
            $table->string('representative_first_name')->nullable();
            $table->string('representative_last_name')->nullable();
            $table->char('representative_sex', 1)->nullable();
            $table->date('representative_birth_date')->nullable();
            $table->string('representative_relationship')->nullable();
            $table->foreignUuid('province_id')->nullable()->constrained();
            $table->foreignUuid('district_id')->nullable()->constrained();
            $table->foreignUuid('administrative_post_id')->nullable()->constrained();
            $table->foreignUuid('locality_id')->nullable()->constrained();
            $table->foreignUuid('community_id')->nullable()->constrained();
            $table->string('status', 16)->default('ACTIVE');
            $table->string('status_reason')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignUuid('status_changed_by')->nullable()->constrained('users');
            $table->foreignUuid('import_batch_id')->nullable()->constrained('household_import_batches');
            $table->timestamps();

            $table->index('representative_name');
            $table->index(['status', 'representative_sex']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('households');
        Schema::dropIfExists('household_import_batches');
    }
};
