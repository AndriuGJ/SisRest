<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $table->foreignId('turno_caja_id')->constrained('turnos_caja')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->enum('estado', ['borrador', 'confirmado', 'anulado'])->default('borrador');
            $table->string('moneda', 3);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('descuento_productos', 14, 2)->default(0);
            $table->string('descuento_tipo', 15)->nullable();
            $table->decimal('descuento_valor', 14, 4)->default(0);
            $table->decimal('descuento_general', 14, 2)->default(0);
            $table->foreignId('motivo_beneficio_id')->nullable()->constrained('motivos_beneficio')->restrictOnDelete();
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('cargo_envio', 14, 2)->default(0);
            $table->decimal('impuestos', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('cobrado_at')->nullable()->index();
            $table->string('clave_idempotencia', 100)->unique();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE cobros ADD CONSTRAINT cobros_check_1 CHECK (subtotal >= 0 AND descuento_productos >= 0 AND descuento_general >= 0 AND cargo_envio >= 0 AND impuestos >= 0 AND total >= 0)');
            DB::statement('ALTER TABLE cobros ADD CONSTRAINT cobros_check_2 CHECK (descuento_productos = 0 OR descuento_general = 0)');
            DB::statement('ALTER TABLE cobros ADD CONSTRAINT cobros_check_3 CHECK (descuento_productos + descuento_general <= subtotal)');
            DB::statement("ALTER TABLE cobros ADD CONSTRAINT cobros_check_4 CHECK (descuento_tipo IS NULL OR descuento_tipo IN ('soles', 'porcentaje'))");
            DB::statement('ALTER TABLE cobros ADD CONSTRAINT cobros_check_5 CHECK (descuento_valor >= 0)');
            DB::statement('ALTER TABLE cobros ADD CONSTRAINT cobros_check_6 CHECK (descuento_general = 0 OR (motivo_beneficio_id IS NOT NULL AND autorizado_por IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros');
    }
};
