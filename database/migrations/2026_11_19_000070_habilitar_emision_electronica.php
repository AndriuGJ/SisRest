<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa', function (Blueprint $table) {
            $table->string('departamento', 100)->nullable();
            $table->string('provincia', 100)->nullable();
            $table->string('distrito', 100)->nullable();
        });
        Schema::table('configuraciones_facturacion', function (Blueprint $table) {
            $table->date('vigente_hasta')->nullable();
        });
        Schema::table('series_comprobante', function (Blueprint $table) {
            $table->string('entorno', 10)->default('beta');
            $table->unique(['tipo_comprobante_id', 'serie', 'entorno'], 'series_tipo_serie_entorno_unique');
        });
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->string('entorno', 10)->default('beta');
            $table->unique(['cobro_id', 'entorno'], 'comprobantes_cobro_entorno_unique');
        });
        Schema::table('series_comprobante', fn (Blueprint $table) => $table->dropUnique(['tipo_comprobante_id', 'serie']));
        Schema::table('comprobantes', fn (Blueprint $table) => $table->dropUnique(['cobro_id']));
        Schema::create('secuencias_resumen', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('entorno', 10);
            $table->unsignedInteger('correlativo')->default(0);
            $table->unique(['fecha', 'entorno']);
        });
        Schema::table('comprobante_envios', function (Blueprint $table) {
            $table->string('xml_nombre', 100)->nullable()->unique();
            $table->string('xml_ruta', 255)->nullable();
            $table->string('xml_sha256', 64)->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('consultando')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('series_comprobante')->select('tipo_comprobante_id', 'serie')->groupBy('tipo_comprobante_id', 'serie')->havingRaw('COUNT(*) > 1')->exists() || DB::table('comprobantes')->whereNotNull('cobro_id')->select('cobro_id')->groupBy('cobro_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('No se puede revertir esta migración: hay documentos o series separados por entorno. Conserva sus datos.');
        }
        Schema::table('comprobantes', fn (Blueprint $table) => $table->unique('cobro_id'));
        Schema::table('series_comprobante', fn (Blueprint $table) => $table->unique(['tipo_comprobante_id', 'serie']));
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropUnique('comprobantes_cobro_entorno_unique');
            $table->dropColumn('entorno');
        });
        Schema::table('comprobante_envios', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->dropUnique(['xml_nombre']);
            $table->dropColumn(['xml_nombre', 'xml_ruta', 'xml_sha256', 'usuario_id', 'consultando']);
        });
        Schema::dropIfExists('secuencias_resumen');
        Schema::table('series_comprobante', function (Blueprint $table) {
            $table->dropUnique('series_tipo_serie_entorno_unique');
            $table->dropColumn('entorno');
        });
        Schema::table('configuraciones_facturacion', fn (Blueprint $table) => $table->dropColumn('vigente_hasta'));
        Schema::table('empresa', fn (Blueprint $table) => $table->dropColumn(['departamento', 'provincia', 'distrito']));
    }
};
