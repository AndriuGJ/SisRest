<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id')->constrained('articulos')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->enum('tipo', ['compra', 'consumo_preparacion', 'produccion', 'entrega', 'reingreso', 'merma', 'consumo_interno', 'ajuste']);
            $table->decimal('cantidad', 16, 4);
            $table->decimal('saldo_anterior', 16, 4);
            $table->decimal('saldo_posterior', 16, 4);
            $table->decimal('costo_unitario', 18, 6)->default(0);
            $table->text('motivo')->nullable();
            $table->nullableMorphs('origen');
            $table->string('clave_idempotencia', 100)->unique();
            $table->timestamp('registrado_at')->index();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_check_1 CHECK (cantidad <> 0)');
            DB::statement('ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_check_2 CHECK (saldo_anterior >= 0 AND saldo_posterior >= 0)');
            DB::statement('ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_check_3 CHECK (saldo_posterior = saldo_anterior + cantidad)');
            DB::statement('ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_check_4 CHECK (costo_unitario >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
