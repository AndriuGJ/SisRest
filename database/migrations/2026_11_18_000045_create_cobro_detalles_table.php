<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobro_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_id')->constrained('cobros')->restrictOnDelete();
            $table->foreignId('pedido_detalle_id')->constrained('pedido_detalles')->restrictOnDelete();
            $table->string('descripcion', 200);
            $table->decimal('cantidad', 16, 4);
            $table->decimal('precio_unitario', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('impuestos', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->boolean('es_cortesia')->default(false);
            $table->unique(['cobro_id', 'pedido_detalle_id']);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE cobro_detalles ADD CONSTRAINT cobro_detalles_check_1 CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE cobro_detalles ADD CONSTRAINT cobro_detalles_check_2 CHECK (precio_unitario >= 0 AND subtotal >= 0 AND descuento >= 0 AND impuestos >= 0 AND total >= 0)');
            DB::statement('ALTER TABLE cobro_detalles ADD CONSTRAINT cobro_detalles_check_3 CHECK (descuento <= subtotal)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_detalles');
    }
};
