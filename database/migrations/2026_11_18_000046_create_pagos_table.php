<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_id')->constrained('cobros')->restrictOnDelete();
            $table->foreignId('medio_pago_id')->constrained('medios_pago')->restrictOnDelete();
            $table->decimal('monto', 14, 2)->default(0);
            $table->decimal('monto_entregado', 14, 2)->default(0);
            $table->decimal('vuelto', 14, 2)->default(0);
            $table->string('referencia', 150)->nullable();
            $table->timestamp('recibido_at')->index();
            $table->string('clave_idempotencia', 100)->unique();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE pagos ADD CONSTRAINT pagos_check_1 CHECK (monto > 0)');
            DB::statement('ALTER TABLE pagos ADD CONSTRAINT pagos_check_2 CHECK (monto_entregado >= 0 AND vuelto >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
