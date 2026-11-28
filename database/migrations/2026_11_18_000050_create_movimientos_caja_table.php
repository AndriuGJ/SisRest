<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_caja_id')->constrained('turnos_caja')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->enum('tipo', ['ingreso', 'egreso', 'retiro']);
            $table->string('concepto', 100);
            $table->decimal('monto', 14, 2)->default(0);
            $table->text('motivo')->nullable();
            $table->nullableMorphs('origen');
            $table->string('clave_idempotencia', 100)->unique();
            $table->timestamp('registrado_at')->index();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE movimientos_caja ADD CONSTRAINT movimientos_caja_check_1 CHECK (monto > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja');
    }
};
