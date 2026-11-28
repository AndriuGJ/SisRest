<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras')->restrictOnDelete();
            $table->foreignId('articulo_id')->constrained('articulos')->restrictOnDelete();
            $table->foreignId('presentacion_articulo_id')->nullable()->constrained('presentaciones_articulo')->restrictOnDelete();
            $table->string('descripcion', 200);
            $table->decimal('cantidad', 16, 4);
            $table->decimal('factor_conversion', 16, 4)->default(1);
            $table->decimal('cantidad_base', 16, 4);
            $table->decimal('costo_unitario', 18, 6)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('impuestos', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE compra_detalles ADD CONSTRAINT compra_detalles_check_1 CHECK (cantidad > 0 AND factor_conversion > 0 AND cantidad_base > 0)');
            DB::statement('ALTER TABLE compra_detalles ADD CONSTRAINT compra_detalles_check_2 CHECK (costo_unitario >= 0 AND descuento >= 0 AND impuestos >= 0 AND total >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_detalles');
    }
};
