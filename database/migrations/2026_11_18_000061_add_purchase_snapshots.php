<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->uuid('clave_registro')->nullable()->unique();
            $table->string('proveedor_nombre', 200)->nullable();
            $table->string('proveedor_documento', 50)->nullable();
            $table->string('documento_nombre', 100)->nullable();
            $table->foreignId('confirmada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('anulada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 500)->nullable();
        });
        Schema::table('compra_detalles', function (Blueprint $table) {
            $table->string('unidad_codigo', 20)->nullable();
            $table->string('presentacion_nombre', 100)->nullable();
        });
        Schema::table('pagos_compra', function (Blueprint $table) {
            $table->string('medio_pago_nombre', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pagos_compra', fn (Blueprint $table) => $table->dropColumn('medio_pago_nombre'));
        Schema::table('compra_detalles', fn (Blueprint $table) => $table->dropColumn(['unidad_codigo', 'presentacion_nombre']));
        Schema::table('compras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmada_por');
            $table->dropConstrainedForeignId('anulada_por');
            $table->dropUnique(['clave_registro']);
            $table->dropColumn(['clave_registro', 'proveedor_nombre', 'proveedor_documento', 'documento_nombre', 'anulada_at', 'motivo_anulacion']);
        });
    }
};
