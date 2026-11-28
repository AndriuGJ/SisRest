<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparaciones', function (Blueprint $table) {
            $table->uuid('clave_registro')->nullable()->unique();
            $table->string('receta_huella', 64)->nullable();
            $table->decimal('rendimiento_receta', 16, 4)->nullable();
            $table->string('producto_nombre', 150)->nullable();
            $table->string('unidad_codigo', 20)->nullable();
            $table->string('motivo_ajuste', 500)->nullable();
            $table->foreignId('confirmada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('anulada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('anulada_at')->nullable();
        });
        Schema::table('preparacion_detalles', function (Blueprint $table) {
            $table->string('descripcion', 150)->nullable();
            $table->string('unidad_codigo', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('preparacion_detalles', fn (Blueprint $table) => $table->dropColumn(['descripcion', 'unidad_codigo']));
        Schema::table('preparaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmada_por');
            $table->dropConstrainedForeignId('anulada_por');
            $table->dropUnique(['clave_registro']);
            $table->dropColumn(['clave_registro', 'receta_huella', 'rendimiento_receta', 'producto_nombre', 'unidad_codigo', 'motivo_ajuste', 'anulada_at']);
        });
    }
};
