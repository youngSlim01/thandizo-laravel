<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cliente desta instalação, mostrado junto do nome THANDIZO: uma só
     * linha, editada pelo administrador em Configuração.
     */
    public function up(): void
    {
        Schema::create('client_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120)->nullable();
            $table->string('programme', 120)->nullable();
            $table->string('tagline', 160)->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_profiles');
    }
};
