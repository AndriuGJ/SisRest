<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->uuid('clave_registro')->nullable()->unique();
            $table->foreignId('turno_apertura_id')->nullable()->constrained('turnos_caja')->restrictOnDelete();
            $table->string('salon_nombre', 100)->nullable();
            $table->string('mesa_codigo', 30)->nullable();
            $table->string('mozo_nombre')->nullable();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('pedido_detalles', function (Blueprint $table) {
            $table->decimal('factor_componente', 16, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedido_detalles', fn (Blueprint $table) => $table->dropColumn('factor_componente'));
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('turno_apertura_id');
            $table->dropConstrainedForeignId('anulado_por');
            $table->dropUnique(['clave_registro']);
            $table->dropColumn(['clave_registro', 'salon_nombre', 'mesa_codigo', 'mozo_nombre', 'motivo_anulacion']);
        });
    }
};
