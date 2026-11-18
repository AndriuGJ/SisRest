<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('singleton')->default(1)->unique();
            $table->string('ruc', 11)->nullable()->unique();
            $table->string('razon_social', 200)->nullable();
            $table->string('nombre_comercial', 150)->nullable();
            $table->string('direccion', 250)->nullable();
            $table->string('ubigeo', 6)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 150)->nullable();
            $table->string('logo_ruta', 255)->nullable();
            $table->string('moneda', 3);
            $table->string('zona_horaria', 50);
            $table->boolean('precios_incluyen_impuestos')->default(true);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE empresa ADD CONSTRAINT empresa_check_1 CHECK (singleton = 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa');
    }
};
