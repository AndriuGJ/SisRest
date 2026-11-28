<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->enum('estado', ['abierta', 'cerrada'])->default('abierta');
            $table->unsignedBigInteger('caja_abierta_id')->nullable()->storedAs("CASE WHEN estado = 'abierta' THEN caja_id ELSE NULL END");
            $table->unique('caja_abierta_id');
            $table->decimal('fondo_inicial', 14, 2)->default(0);
            $table->timestamp('abierta_at');
            $table->timestamp('cerrada_at')->nullable();
            $table->foreignId('cerrada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('observaciones')->nullable();
            $table->index(['usuario_id', 'abierta_at']);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE turnos_caja ADD CONSTRAINT turnos_caja_check_1 CHECK (fondo_inicial >= 0)');
            DB::statement("ALTER TABLE turnos_caja ADD CONSTRAINT turnos_caja_check_2 CHECK ((estado = 'abierta' AND cerrada_at IS NULL) OR (estado = 'cerrada' AND cerrada_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('turnos_caja');
    }
};
