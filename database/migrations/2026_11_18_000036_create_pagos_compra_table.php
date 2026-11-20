<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras')->restrictOnDelete();
            $table->foreignId('medio_pago_id')->constrained('medios_pago')->restrictOnDelete();
            $table->foreignId('turno_caja_id')->nullable()->constrained('turnos_caja')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->enum('origen_fondos', ['caja', 'empresa', 'dueno']);
            $table->decimal('monto', 14, 2)->default(0);
            $table->timestamp('pagado_at')->index();
            $table->string('referencia', 150)->nullable();
            $table->string('clave_idempotencia', 100)->unique();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE pagos_compra ADD CONSTRAINT pagos_compra_check_1 CHECK (monto > 0)');
            DB::statement("ALTER TABLE pagos_compra ADD CONSTRAINT pagos_compra_check_2 CHECK ((origen_fondos = 'caja' AND turno_caja_id IS NOT NULL) OR (origen_fondos <> 'caja' AND turno_caja_id IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_compra');
    }
};
