<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anulaciones_pedido', function (Blueprint $table) {
            $table->id();
            $table->uuid('clave_idempotencia')->unique();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $table->foreignId('pedido_detalle_id')->constrained('pedido_detalles')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('usuario_nombre');
            $table->decimal('cantidad', 16, 4);
            $table->string('motivo', 500);
            $table->timestamp('anulado_at');
            $table->timestamps();
        });
        Schema::create('anulacion_pedido_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anulacion_pedido_id')->constrained('anulaciones_pedido')->restrictOnDelete();
            $table->foreignId('pedido_detalle_id')->constrained('pedido_detalles')->restrictOnDelete();
            $table->foreignId('articulo_id')->constrained('articulos')->restrictOnDelete();
            $table->string('descripcion', 200);
            $table->decimal('cantidad_liberada', 16, 4);
            $table->decimal('cantidad_merma', 16, 4);
            $table->json('avance_antes')->nullable();
            $table->json('avance_despues')->nullable();
            $table->timestamps();
        });
        Schema::create('beneficios_pedido', function (Blueprint $table) {
            $table->id();
            $table->uuid('clave_idempotencia')->unique();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $table->foreignId('pedido_detalle_id')->constrained('pedido_detalles')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('usuario_nombre');
            $table->string('accion', 20);
            $table->string('motivo', 500);
            $table->json('antes');
            $table->json('despues');
            $table->timestamp('autorizado_at');
            $table->timestamps();
        });
        Schema::table('pedido_detalles', function (Blueprint $table) {
            $table->string('beneficio_motivo_nombre', 100)->nullable();
            $table->string('beneficio_motivo_texto', 500)->nullable();
            $table->string('beneficio_autorizado_nombre')->nullable();
            $table->timestamp('beneficio_autorizado_at')->nullable();
        });
        Schema::table('cobros', function (Blueprint $table) {
            $table->string('beneficio_motivo_nombre', 100)->nullable();
            $table->string('beneficio_motivo_texto', 500)->nullable();
            $table->string('beneficio_autorizado_nombre')->nullable();
        });
        Schema::table('cobro_detalles', function (Blueprint $table) {
            $table->decimal('descuento_producto', 14, 2)->default(0);
            $table->decimal('descuento_general', 14, 2)->default(0);
            $table->json('beneficio_resumen')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cobro_detalles', fn (Blueprint $table) => $table->dropColumn(['descuento_producto', 'descuento_general', 'beneficio_resumen']));
        Schema::table('cobros', fn (Blueprint $table) => $table->dropColumn(['beneficio_motivo_nombre', 'beneficio_motivo_texto', 'beneficio_autorizado_nombre']));
        Schema::table('pedido_detalles', fn (Blueprint $table) => $table->dropColumn(['beneficio_motivo_nombre', 'beneficio_motivo_texto', 'beneficio_autorizado_nombre', 'beneficio_autorizado_at']));
        Schema::dropIfExists('beneficios_pedido');
        Schema::dropIfExists('anulacion_pedido_detalles');
        Schema::dropIfExists('anulaciones_pedido');
    }
};
