<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparacion_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preparacion_id')->constrained('preparaciones')->restrictOnDelete();
            $table->foreignId('articulo_id')->constrained('articulos')->restrictOnDelete();
            $table->decimal('cantidad_estandar', 16, 4);
            $table->decimal('cantidad_utilizada', 16, 4);
            $table->decimal('costo_unitario', 18, 6)->default(0);
            $table->decimal('costo_total', 18, 6)->default(0);
            $table->unique(['preparacion_id', 'articulo_id']);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE preparacion_detalles ADD CONSTRAINT preparacion_detalles_check_1 CHECK (cantidad_estandar >= 0)');
            DB::statement('ALTER TABLE preparacion_detalles ADD CONSTRAINT preparacion_detalles_check_2 CHECK (cantidad_utilizada >= 0)');
            DB::statement('ALTER TABLE preparacion_detalles ADD CONSTRAINT preparacion_detalles_check_3 CHECK (costo_unitario >= 0 AND costo_total >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('preparacion_detalles');
    }
};
