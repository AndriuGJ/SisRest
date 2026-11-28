<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentaciones_articulo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id')->constrained('articulos')->restrictOnDelete();
            $table->string('nombre', 100);
            $table->decimal('factor_conversion', 16, 4);
            $table->boolean('activo')->default(true);
            $table->unique(['articulo_id', 'nombre']);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE presentaciones_articulo ADD CONSTRAINT presentaciones_articulo_check_1 CHECK (factor_conversion > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('presentaciones_articulo');
    }
};
