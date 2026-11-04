<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Junta o que no Supabase estava em duas tabelas: auth.users (email e
    // palavra-passe) e public.app_users (nome, papel, estado).
    //
    // O id é UUID para manter os mesmos ids na migração dos dados.
    // province_id e agrodealer_id ficam sem chave estrangeira por agora: as
    // tabelas provinces (nível 0) e agrodealers (nível 3) ainda não existem.
    // Cada uma dessas migrações acrescenta a sua chave estrangeira.
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 32);
            $table->string('status', 16)->default('ACTIVE');
            $table->uuid('province_id')->nullable()->index();
            $table->uuid('agrodealer_id')->nullable()->index();
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
