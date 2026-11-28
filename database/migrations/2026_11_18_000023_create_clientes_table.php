<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_documento_identidad_id')->nullable()->constrained('tipos_documento_identidad')->restrictOnDelete();
            $table->string('numero_documento', 20)->nullable();
            $table->enum('tipo', ['persona', 'empresa'])->default('persona');
            $table->string('nombre', 200);
            $table->string('direccion', 250)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 150)->nullable();
            $table->boolean('activo')->default(true);
            $table->unique(['tipo_documento_identidad_id', 'numero_documento'], 'clientes_documento_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
