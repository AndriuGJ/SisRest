<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->unique('producto_id');
            $table->decimal('rendimiento', 16, 4)->default(1);
            $table->text('observaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE recetas ADD CONSTRAINT recetas_check_1 CHECK (rendimiento > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recetas');
    }
};
