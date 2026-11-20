<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devoluciones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->foreignId('cobro_id')->constrained('cobros')->restrictOnDelete();
            $table->foreignId('turno_caja_id')->constrained('turnos_caja')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('autorizado_por')->constrained('users')->restrictOnDelete();
            $table->text('motivo');
            $table->enum('estado', ['borrador', 'confirmada', 'anulada'])->default('borrador');
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('devuelta_at')->nullable()->index();
            $table->string('clave_idempotencia', 100)->unique();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE devoluciones ADD CONSTRAINT devoluciones_check_1 CHECK (total > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devoluciones');
    }
};
