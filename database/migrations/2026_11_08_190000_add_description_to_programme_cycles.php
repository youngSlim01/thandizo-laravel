<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Âmbito do ciclo (ex.: "Acções antecipadas à seca, Out–Dez"), ao lado do
     * nome que o identifica (ex.: "AA - Janela 1").
     */
    public function up(): void
    {
        Schema::table('programme_cycles', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('programme_cycles', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
