<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros', function (Blueprint $table) {
            $table->string('grupo_pagador', 100)->nullable();
            $table->string('cliente_nombre')->nullable();
            $table->string('cliente_tipo_documento', 100)->nullable();
            $table->string('cliente_numero_documento', 20)->nullable();
            $table->string('cliente_direccion', 250)->nullable();
            $table->string('caja_nombre', 100)->nullable();
            $table->string('cajero_nombre')->nullable();
        });
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('medio_pago_nombre', 100)->nullable();
            $table->boolean('afecta_efectivo')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pagos', fn (Blueprint $table) => $table->dropColumn(['medio_pago_nombre', 'afecta_efectivo']));
        Schema::table('cobros', fn (Blueprint $table) => $table->dropColumn(['grupo_pagador', 'cliente_nombre', 'cliente_tipo_documento', 'cliente_numero_documento', 'cliente_direccion', 'caja_nombre', 'cajero_nombre']));
    }
};
