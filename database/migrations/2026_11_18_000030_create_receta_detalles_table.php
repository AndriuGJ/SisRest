<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receta_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receta_id')->constrained('recetas')->restrictOnDelete();
            $table->foreignId('articulo_id')->constrained('articulos')->restrictOnDelete();
            $table->decimal('cantidad', 16, 4);
            $table->unique(['receta_id', 'articulo_id']);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE receta_detalles ADD CONSTRAINT receta_detalles_check_1 CHECK (cantidad > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('receta_detalles');
    }
};
