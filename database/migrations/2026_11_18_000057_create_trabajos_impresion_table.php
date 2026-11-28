<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trabajos_impresion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impresora_id')->constrained('impresoras')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->enum('tipo', ['comanda', 'precuenta', 'comprobante']);
            $table->morphs('documento');
            $table->json('contenido');
            $table->enum('estado', ['pendiente', 'procesando', 'enviado', 'error', 'resultado_incierto'])->default('pendiente');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->foreignId('reimpresion_de_id')->nullable()->constrained('trabajos_impresion')->restrictOnDelete();
            $table->text('ultimo_error')->nullable();
            $table->timestamp('enviado_at')->nullable();
            $table->string('clave_idempotencia', 100)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trabajos_impresion');
    }
};
