<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impresoras', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 100);
            $table->string('host', 253)->nullable();
            $table->unsignedSmallInteger('puerto')->default(9100);
            $table->unsignedSmallInteger('ancho_mm')->default(80);
            $table->foreignId('area_preparacion_id')->nullable()->constrained('areas_preparacion')->restrictOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE impresoras ADD CONSTRAINT impresoras_check_1 CHECK (puerto > 0)');
            DB::statement('ALTER TABLE impresoras ADD CONSTRAINT impresoras_check_2 CHECK (ancho_mm > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('impresoras');
    }
};
