<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devolucion_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devolucion_id')->constrained('devoluciones')->restrictOnDelete();
            $table->foreignId('cobro_detalle_id')->constrained('cobro_detalles')->restrictOnDelete();
            $table->decimal('cantidad', 16, 4);
            $table->decimal('importe', 14, 2)->default(0);
            $table->enum('destino_producto', ['sin_reingreso', 'reingreso', 'merma'])->default('sin_reingreso');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE devolucion_detalles ADD CONSTRAINT devolucion_detalles_check_1 CHECK (cantidad > 0 AND importe >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devolucion_detalles');
    }
};
