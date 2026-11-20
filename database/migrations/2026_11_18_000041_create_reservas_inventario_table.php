<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservas_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_detalle_id')->constrained('pedido_detalles')->restrictOnDelete();
            $table->foreignId('articulo_id')->constrained('articulos')->restrictOnDelete();
            $table->decimal('cantidad', 16, 4);
            $table->decimal('cantidad_consumida', 16, 4)->default(0);
            $table->decimal('cantidad_liberada', 16, 4)->default(0);
            $table->string('clave_idempotencia', 100)->unique();
            $table->unique(['pedido_detalle_id', 'articulo_id']);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE reservas_inventario ADD CONSTRAINT reservas_inventario_check_1 CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE reservas_inventario ADD CONSTRAINT reservas_inventario_check_2 CHECK (cantidad_consumida >= 0 AND cantidad_liberada >= 0 AND cantidad_consumida + cantidad_liberada <= cantidad)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas_inventario');
    }
};
