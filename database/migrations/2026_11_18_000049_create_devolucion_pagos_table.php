<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devolucion_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devolucion_id')->constrained('devoluciones')->restrictOnDelete();
            $table->foreignId('pago_original_id')->nullable()->constrained('pagos')->restrictOnDelete();
            $table->foreignId('medio_pago_id')->constrained('medios_pago')->restrictOnDelete();
            $table->decimal('monto', 14, 2)->default(0);
            $table->string('referencia', 150)->nullable();
            $table->string('clave_idempotencia', 100)->unique();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE devolucion_pagos ADD CONSTRAINT devolucion_pagos_check_1 CHECK (monto > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devolucion_pagos');
    }
};
