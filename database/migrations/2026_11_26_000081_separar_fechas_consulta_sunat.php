<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobante_envios', function (Blueprint $table) {
            $table->timestamp('consulta_iniciada_at')->nullable();
            $table->timestamp('consulta_finalizada_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('comprobante_envios', function (Blueprint $table) {
            $table->dropColumn(['consulta_iniciada_at', 'consulta_finalizada_at']);
        });
    }
};
