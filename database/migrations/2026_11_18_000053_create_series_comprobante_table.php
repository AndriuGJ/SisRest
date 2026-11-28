<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series_comprobante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_comprobante_id')->constrained('tipos_comprobante')->restrictOnDelete();
            $table->string('serie', 4);
            $table->unsignedBigInteger('ultimo_correlativo')->default(0);
            $table->boolean('activo')->default(true);
            $table->unique(['tipo_comprobante_id', 'serie']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_comprobante');
    }
};
