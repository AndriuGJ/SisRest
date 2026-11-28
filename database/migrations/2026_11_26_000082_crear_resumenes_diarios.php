<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resumenes_diarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresa')->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('envio_historico_id')->nullable()->unique()->constrained('comprobante_envios')->restrictOnDelete();
            $table->uuid('clave_idempotencia')->unique();
            $table->string('entorno', 10);
            $table->string('nombre', 100);
            $table->unsignedInteger('correlativo');
            $table->date('fecha_emision');
            $table->date('fecha_generacion');
            $table->string('moneda', 3)->default('PEN');
            $table->decimal('total', 18, 2);
            $table->json('emisor');
            $table->string('estado', 30)->default('pendiente');
            $table->string('ticket', 100)->nullable();
            $table->string('xml_ruta')->nullable();
            $table->string('xml_sha256', 64)->nullable();
            $table->string('cdr_ruta')->nullable();
            $table->string('cdr_recibido_ruta')->nullable();
            $table->string('cdr_recibido_sha256', 64)->nullable();
            $table->string('codigo_respuesta', 10)->nullable();
            $table->text('mensaje')->nullable();
            $table->json('diagnostico')->nullable();
            $table->timestamp('envio_iniciado_at')->nullable();
            $table->timestamp('envio_finalizado_at')->nullable();
            $table->boolean('consultando')->default(false);
            $table->timestamp('consulta_iniciada_at')->nullable();
            $table->timestamp('consulta_finalizada_at')->nullable();
            $table->timestamps();
            $table->unique(['entorno', 'nombre']);
            $table->index(['entorno', 'fecha_emision', 'estado']);
        });
        Schema::create('resumen_diario_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resumen_diario_id')->constrained('resumenes_diarios')->restrictOnDelete();
            $table->foreignId('comprobante_id')->constrained('comprobantes')->restrictOnDelete();
            // NULL only after a verified rejection; preserves history while allowing a corrected RC.
            $table->foreignId('comprobante_activo_id')->nullable()->unique()->constrained('comprobantes')->restrictOnDelete();
            $table->unsignedInteger('linea');
            $table->string('numero_fiscal', 30);
            $table->unique(['resumen_diario_id', 'comprobante_id']);
            $table->unique(['resumen_diario_id', 'linea']);
        });
        Schema::create('resumen_diario_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resumen_diario_id')->constrained('resumenes_diarios')->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('operacion', 30);
            $table->string('estado', 30);
            $table->string('codigo', 10)->nullable();
            $table->text('mensaje')->nullable();
            $table->json('diagnostico')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('resumenes_diarios')->exists()) {
            throw new RuntimeException('No se elimina la trazabilidad de resúmenes existentes.');
        }
        Schema::dropIfExists('resumen_diario_eventos');
        Schema::dropIfExists('resumen_diario_detalles');
        Schema::dropIfExists('resumenes_diarios');
    }
};
