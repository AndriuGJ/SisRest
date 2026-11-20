<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_facturacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresa')->restrictOnDelete();
            $table->unique('empresa_id');
            $table->boolean('habilitada')->default(false);
            $table->enum('entorno', ['beta', 'produccion'])->default('beta');
            $table->text('usuario_sol')->nullable();
            $table->text('clave_sol')->nullable();
            $table->text('clave_certificado')->nullable();
            $table->string('certificado_ruta', 255)->nullable();
            $table->decimal('tasa_impuesto', 7, 4)->nullable();
            $table->date('vigente_desde')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE configuraciones_facturacion ADD CONSTRAINT configuraciones_facturacion_check_1 CHECK (tasa_impuesto IS NULL OR (tasa_impuesto >= 0 AND tasa_impuesto <= 100))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_facturacion');
    }
};
