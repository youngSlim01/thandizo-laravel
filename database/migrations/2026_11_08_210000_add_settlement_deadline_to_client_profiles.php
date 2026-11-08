<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prazo, em dias, para as Finanças validarem uma reconciliação depois de
     * a receberem (alterável na Configuração).
     */
    public function up(): void
    {
        Schema::table('client_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('settlement_deadline_days')->default(15)->after('tagline');
        });
    }

    public function down(): void
    {
        Schema::table('client_profiles', function (Blueprint $table) {
            $table->dropColumn('settlement_deadline_days');
        });
    }
};
