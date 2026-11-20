<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobante_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained('comprobantes')->restrictOnDelete();
            $table->unsignedSmallInteger('intento');
            $table->enum('estado', ['pendiente', 'en_proceso', 'enviado', 'aceptado', 'observado', 'rechazado', 'error_conexion', 'resultado_incierto'])->default('pendiente');
            $table->string('ticket', 150)->nullable();
            $table->string('codigo_respuesta', 20)->nullable();
            $table->text('mensaje')->nullable();
            $table->string('cdr_ruta', 255)->nullable();
            $table->timestamp('iniciado_at')->nullable();
            $table->timestamp('finalizado_at')->nullable();
            $table->unique(['comprobante_id', 'intento']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobante_envios');
    }
};
