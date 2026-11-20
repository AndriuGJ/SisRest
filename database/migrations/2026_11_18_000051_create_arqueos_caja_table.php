<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arqueos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_caja_id')->constrained('turnos_caja')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->enum('tipo', ['parcial', 'cierre'])->default('cierre');
            $table->unsignedBigInteger('cierre_turno_id')->nullable()->storedAs("CASE WHEN tipo = 'cierre' THEN turno_caja_id ELSE NULL END");
            $table->unique('cierre_turno_id');
            $table->decimal('efectivo_esperado', 14, 2)->default(0);
            $table->decimal('efectivo_contado', 14, 2)->default(0);
            $table->decimal('diferencia', 14, 2)->default(0);
            $table->timestamp('contado_at');
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE arqueos_caja ADD CONSTRAINT arqueos_caja_check_1 CHECK (efectivo_contado >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('arqueos_caja');
    }
};
