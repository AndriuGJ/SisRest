<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('serie_comprobante_id')->constrained('series_comprobante')->restrictOnDelete();
            $table->unsignedBigInteger('correlativo');
            $table->foreignId('cobro_id')->nullable()->constrained('cobros')->restrictOnDelete();
            $table->unique('cobro_id');
            $table->foreignId('comprobante_referencia_id')->nullable()->constrained('comprobantes')->restrictOnDelete();
            $table->foreignId('devolucion_id')->nullable()->constrained('devoluciones')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->date('fecha_emision')->index();
            $table->string('moneda', 3);
            $table->enum('estado', ['emitido', 'anulado'])->default('emitido');
            $table->enum('estado_sunat', ['pendiente', 'enviado', 'aceptado', 'observado', 'rechazado', 'desconocido'])->default('pendiente');
            $table->json('emisor');
            $table->json('receptor');
            $table->json('configuracion_tributaria');
            $table->decimal('valor_venta', 14, 2)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('operaciones_gratuitas', 14, 2)->default(0);
            $table->decimal('impuestos', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('codigo_motivo_nota', 4)->nullable();
            $table->text('descripcion_motivo_nota')->nullable();
            $table->string('xml_ruta', 255)->nullable();
            $table->string('cdr_ruta', 255)->nullable();
            $table->string('pdf_ruta', 255)->nullable();
            $table->string('hash_documento', 128)->nullable();
            $table->string('clave_idempotencia', 100)->unique();
            $table->unique(['serie_comprobante_id', 'correlativo']);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE comprobantes ADD CONSTRAINT comprobantes_check_1 CHECK (correlativo > 0)');
            DB::statement('ALTER TABLE comprobantes ADD CONSTRAINT comprobantes_check_2 CHECK (valor_venta >= 0 AND descuento >= 0 AND operaciones_gratuitas >= 0 AND impuestos >= 0 AND total >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes');
    }
};
