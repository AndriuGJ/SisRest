<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articulos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 150);
            $table->enum('tipo', ['insumo', 'preparado', 'reventa']);
            $table->foreignId('unidad_medida_id')->constrained('unidades_medida')->restrictOnDelete();
            $table->decimal('stock_actual', 16, 4)->default(0);
            $table->decimal('stock_reservado', 16, 4)->default(0);
            $table->decimal('stock_minimo', 16, 4)->default(0);
            $table->decimal('costo_promedio', 18, 6)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE articulos ADD CONSTRAINT articulos_check_1 CHECK (stock_actual >= 0)');
            DB::statement('ALTER TABLE articulos ADD CONSTRAINT articulos_check_2 CHECK (stock_reservado >= 0 AND stock_reservado <= stock_actual)');
            DB::statement('ALTER TABLE articulos ADD CONSTRAINT articulos_check_3 CHECK (stock_minimo >= 0)');
            DB::statement('ALTER TABLE articulos ADD CONSTRAINT articulos_check_4 CHECK (costo_promedio >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('articulos');
    }
};
