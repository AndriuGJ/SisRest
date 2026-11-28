<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->enum('tipo', ['plato', 'bebida', 'reventa', 'combo', 'adicional', 'menu']);
            $table->foreignId('categoria_producto_id')->nullable()->constrained('categorias_producto')->restrictOnDelete();
            $table->foreignId('articulo_id')->nullable()->constrained('articulos')->restrictOnDelete();
            $table->unique('articulo_id');
            $table->foreignId('area_preparacion_id')->nullable()->constrained('areas_preparacion')->restrictOnDelete();
            $table->decimal('precio_venta', 14, 2)->default(0);
            $table->string('imagen_ruta', 255)->nullable();
            $table->boolean('visible_pos')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE productos ADD CONSTRAINT productos_check_1 CHECK (precio_venta >= 0)');
            DB::statement("ALTER TABLE productos ADD CONSTRAINT productos_check_2 CHECK ((tipo = 'combo' AND articulo_id IS NULL) OR (tipo <> 'combo' AND articulo_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
