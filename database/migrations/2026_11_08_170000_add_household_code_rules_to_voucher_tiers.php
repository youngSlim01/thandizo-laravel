<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CODIGO AF gerado pelo sistema: só os escalões que não exigem CODIGO AF
     * aceitam agregados sem código, e esses recebem um código definitivo
     * TZ-<DIS>-NNNNNN, marcado como gerado.
     */
    public function up(): void
    {
        Schema::table('voucher_tiers', function (Blueprint $table) {
            $table->boolean('requires_household_code')->default(true)->after('is_default');
        });
        Schema::table('households', function (Blueprint $table) {
            $table->boolean('household_code_generated')->default(false)->after('household_code');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('household_code_generated');
        });
        Schema::table('voucher_tiers', function (Blueprint $table) {
            $table->dropColumn('requires_household_code');
        });
    }
};
