<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmaciones_pedido', function (Blueprint $table) {
            $table->id();
            $table->uuid('clave_idempotencia')->unique();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $table->foreignId('turno_caja_id')->constrained('turnos_caja')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('numero_lote');
            $table->timestamp('confirmado_at');
            $table->timestamps();
            $table->unique(['pedido_id', 'numero_lote']);
        });
        Schema::table('pedido_detalles', function (Blueprint $table) {
            $table->foreignId('confirmacion_pedido_id')->nullable()->constrained('confirmaciones_pedido')->restrictOnDelete();
        });
        Schema::table('envios_cocina', function (Blueprint $table) {
            $table->foreignId('confirmacion_pedido_id')->nullable()->constrained('confirmaciones_pedido')->restrictOnDelete();
            $table->string('area_nombre', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('envios_cocina', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmacion_pedido_id');
            $table->dropColumn('area_nombre');
        });
        Schema::table('pedido_detalles', fn (Blueprint $table) => $table->dropConstrainedForeignId('confirmacion_pedido_id'));
        Schema::dropIfExists('confirmaciones_pedido');
    }
};
