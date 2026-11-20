<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('detalle_padre_id')->nullable()->constrained('pedido_detalles')->restrictOnDelete();
            $table->enum('tipo_linea', ['producto', 'componente', 'adicional'])->default('producto');
            $table->string('descripcion', 200);
            $table->foreignId('area_preparacion_id')->nullable()->constrained('areas_preparacion')->restrictOnDelete();
            $table->decimal('cantidad', 16, 4);
            $table->decimal('cantidad_anulada', 16, 4)->default(0);
            $table->decimal('cantidad_entregada', 16, 4)->default(0);
            $table->decimal('cantidad_cobrada', 16, 4)->default(0);
            $table->decimal('precio_unitario', 14, 2)->default(0);
            $table->enum('estado', ['borrador', 'confirmado', 'anulado'])->default('borrador');
            $table->text('nota')->nullable();
            $table->boolean('es_cortesia')->default(false);
            $table->foreignId('motivo_beneficio_id')->nullable()->constrained('motivos_beneficio')->restrictOnDelete();
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('descuento_tipo', 15)->nullable();
            $table->decimal('descuento_valor', 14, 4)->default(0);
            $table->decimal('descuento_importe', 14, 2)->default(0);
            $table->timestamp('confirmado_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE pedido_detalles ADD CONSTRAINT pedido_detalles_check_1 CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE pedido_detalles ADD CONSTRAINT pedido_detalles_check_2 CHECK (cantidad_anulada >= 0 AND cantidad_anulada <= cantidad)');
            DB::statement('ALTER TABLE pedido_detalles ADD CONSTRAINT pedido_detalles_check_3 CHECK (cantidad_entregada >= 0 AND cantidad_entregada <= cantidad - cantidad_anulada)');
            DB::statement('ALTER TABLE pedido_detalles ADD CONSTRAINT pedido_detalles_check_4 CHECK (cantidad_cobrada >= 0 AND cantidad_cobrada <= cantidad)');
            DB::statement('ALTER TABLE pedido_detalles ADD CONSTRAINT pedido_detalles_check_5 CHECK (precio_unitario >= 0 AND descuento_valor >= 0 AND descuento_importe >= 0)');
            DB::statement("ALTER TABLE pedido_detalles ADD CONSTRAINT pedido_detalles_check_6 CHECK (descuento_tipo IS NULL OR descuento_tipo IN ('soles', 'porcentaje'))");
            DB::statement("ALTER TABLE pedido_detalles ADD CONSTRAINT pedido_detalles_check_7 CHECK (tipo_linea <> 'componente' OR (detalle_padre_id IS NOT NULL AND precio_unitario = 0))");
            DB::statement('ALTER TABLE pedido_detalles ADD CONSTRAINT pedido_detalles_check_8 CHECK (es_cortesia = 0 OR (motivo_beneficio_id IS NOT NULL AND autorizado_por IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_detalles');
    }
};
