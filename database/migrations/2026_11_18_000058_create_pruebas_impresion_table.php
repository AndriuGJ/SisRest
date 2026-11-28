<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pruebas_impresion', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('impresora_id')->constrained('impresoras')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->json('configuracion');
            $table->enum('estado', ['procesando', 'enviado', 'error', 'resultado_incierto']);
            $table->string('mensaje', 500);
            $table->timestamps();
            $table->index(['impresora_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pruebas_impresion');
    }
};
