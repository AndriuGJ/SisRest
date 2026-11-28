<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobante_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained('comprobantes')->restrictOnDelete();
            $table->foreignId('cobro_detalle_id')->nullable()->constrained('cobro_detalles')->restrictOnDelete();
            $table->string('codigo_producto', 40);
            $table->string('descripcion', 250);
            $table->string('unidad_sunat', 3);
            $table->decimal('cantidad', 16, 4);
            $table->decimal('valor_unitario', 18, 6)->default(0);
            $table->decimal('precio_unitario', 18, 6)->default(0);
            $table->decimal('valor_referencial', 18, 6)->default(0);
            $table->string('tipo_afectacion_igv', 2);
            $table->decimal('tasa_impuesto', 7, 4);
            $table->decimal('base_imponible', 14, 2)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('impuestos', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE comprobante_detalles ADD CONSTRAINT comprobante_detalles_check_1 CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE comprobante_detalles ADD CONSTRAINT comprobante_detalles_check_2 CHECK (tasa_impuesto >= 0 AND tasa_impuesto <= 100)');
            DB::statement('ALTER TABLE comprobante_detalles ADD CONSTRAINT comprobante_detalles_check_3 CHECK (valor_unitario >= 0 AND precio_unitario >= 0 AND valor_referencial >= 0)');
            DB::statement('ALTER TABLE comprobante_detalles ADD CONSTRAINT comprobante_detalles_check_4 CHECK (base_imponible >= 0 AND descuento >= 0 AND impuestos >= 0 AND total >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobante_detalles');
    }
};
