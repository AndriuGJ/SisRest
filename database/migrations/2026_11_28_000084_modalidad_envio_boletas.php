<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_facturacion', function (Blueprint $table) {
            $table->string('modalidad_boletas_beta', 20)->default('individual');
            $table->string('modalidad_boletas_produccion', 20)->default('resumen_diario');
            $table->boolean('individual_produccion_confirmada')->default(false);
        });
        // Existing signed snapshots are deliberately left untouched: absent mode means legacy RC.
    }

    public function down(): void
    {
        Schema::table('configuraciones_facturacion', fn (Blueprint $table) => $table->dropColumn(['modalidad_boletas_beta', 'modalidad_boletas_produccion', 'individual_produccion_confirmada']));
    }
};
