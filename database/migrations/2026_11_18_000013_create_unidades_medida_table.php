<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 10)->unique();
            $table->string('nombre', 80);
            $table->enum('magnitud', ['masa', 'volumen', 'unidad']);
            $table->decimal('factor_base', 16, 6)->default(1);
            $table->string('codigo_sunat', 3)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE unidades_medida ADD CONSTRAINT unidades_medida_check_1 CHECK (factor_base > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_medida');
    }
};
