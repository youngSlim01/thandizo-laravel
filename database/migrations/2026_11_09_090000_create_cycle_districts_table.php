<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planeamento do ciclo por distrito: a meta de agregados e as datas da
     * feira. Só se vende nos dias da feira do distrito do comerciante.
     */
    public function up(): void
    {
        Schema::create('cycle_districts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->constrained('programme_cycles');
            $table->foreignUuid('district_id')->constrained();
            $table->unsignedInteger('target_households')->nullable();
            $table->date('fair_starts_on')->nullable();
            $table->date('fair_ends_on')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['cycle_id', 'district_id']);
        });
        DB::statement('alter table cycle_districts add constraint cycle_districts_fair_check check (fair_ends_on is null or fair_starts_on is null or fair_ends_on >= fair_starts_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_districts');
    }
};
