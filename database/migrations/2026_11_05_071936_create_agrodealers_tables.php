<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nível 3: agrodealers e os seus pontos de venda (POS).
     *
     * Equivalente a public.agrodealers e public.agrodealer_pos. district_id
     * fica opcional como no sistema antigo (agrodealers antigos sem distrito
     * existem e não conseguem vender até lhes ser dado um).
     */
    public function up(): void
    {
        Schema::create('agrodealers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->foreignUuid('province_id')->nullable()->constrained();
            $table->foreignUuid('district_id')->nullable()->constrained();
            $table->string('address')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamps();

            $table->index(['status', 'name']);
        });

        Schema::create('agrodealer_pos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agrodealer_id')->constrained();
            $table->string('pos_code', 40)->unique();
            $table->string('device_label')->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('agrodealer_id')->references('id')->on('agrodealers');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['agrodealer_id']);
        });
        Schema::dropIfExists('agrodealer_pos');
        Schema::dropIfExists('agrodealers');
    }
};
