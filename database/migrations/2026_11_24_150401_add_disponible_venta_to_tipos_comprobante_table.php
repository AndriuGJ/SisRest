<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_comprobante', function (Blueprint $table) {
            $table->boolean('disponible_venta')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('tipos_comprobante', function (Blueprint $table) {
            $table->dropColumn('disponible_venta');
        });
    }
};
