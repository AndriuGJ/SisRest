<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_delivery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $table->unique('pedido_id');
            $table->string('destinatario', 150);
            $table->string('telefono', 30);
            $table->string('direccion', 250);
            $table->string('referencia', 250)->nullable();
            $table->enum('tipo_transporte', ['propio', 'taxi'])->default('propio');
            $table->string('repartidor_nombre', 150)->nullable();
            $table->string('repartidor_telefono', 30)->nullable();
            $table->decimal('costo_envio', 14, 2)->default(0);
            $table->enum('estado', ['pendiente', 'en_preparacion', 'listo', 'en_camino', 'entregado', 'cancelado'])->default('pendiente');
            $table->timestamp('salida_at')->nullable();
            $table->timestamp('entregada_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE entregas_delivery ADD CONSTRAINT entregas_delivery_check_1 CHECK (costo_envio >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_delivery');
    }
};
