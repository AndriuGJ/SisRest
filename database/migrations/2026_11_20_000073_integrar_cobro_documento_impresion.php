<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procesos_cobro', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_id')->unique()->constrained('cobros')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('caja_id')->constrained('cajas')->restrictOnDelete();
            $table->string('documento', 10);
            $table->boolean('liberar');
            $table->json('fiscal');
            $table->uuid('clave_emision')->unique();
            $table->uuid('clave_impresion')->unique();
            $table->foreignId('comprobante_id')->nullable()->constrained('comprobantes')->restrictOnDelete();
            $table->foreignId('trabajo_impresion_id')->nullable()->constrained('trabajos_impresion')->restrictOnDelete();
            $table->timestamp('finalizado_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procesos_cobro');
    }
};
