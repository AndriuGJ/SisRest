<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_anulacion', function (Blueprint $table) {
            $table->id();

            // Solo habrá una configuración por restaurante.
            $table->unsignedTinyInteger('singleton')
                ->default(1)
                ->unique();

            // Nunca guardaremos la clave original.
            $table->string('clave_hash')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_anulacion');
    }
};
