<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobante_envios', function (Blueprint $table) {
            $table->json('diagnostico_envio')->nullable();
            $table->json('diagnostico_consulta')->nullable();
            $table->string('cdr_recibido_ruta')->nullable();
            $table->string('cdr_recibido_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('comprobante_envios', function (Blueprint $table) {
            $table->dropColumn(['diagnostico_envio', 'diagnostico_consulta', 'cdr_recibido_ruta', 'cdr_recibido_sha256']);
        });
    }
};
