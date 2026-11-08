<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logótipos do cliente impressos nos cartões, da esquerda para a direita.
     * Ficam na base de dados (e não em ficheiros) para se mudarem no próprio
     * sistema, sem novo deploy; o conteúdo vai em base64.
     */
    public function up(): void
    {
        Schema::create('card_logos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedTinyInteger('position')->unique();
            $table->string('original_name');
            $table->string('mime_type', 30);
            $table->longText('content');
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_logos');
    }
};
