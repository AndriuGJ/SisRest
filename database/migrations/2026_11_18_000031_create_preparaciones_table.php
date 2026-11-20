<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparaciones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('receta_id')->nullable()->constrained('recetas')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->date('fecha')->index();
            $table->enum('estado', ['borrador', 'confirmada', 'anulada'])->default('borrador');
            $table->decimal('cantidad_producida', 16, 4);
            $table->decimal('costo_total', 18, 6)->default(0);
            $table->timestamp('confirmada_at')->nullable();
            $table->text('observaciones')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE preparaciones ADD CONSTRAINT preparaciones_check_1 CHECK (cantidad_producida > 0)');
            DB::statement('ALTER TABLE preparaciones ADD CONSTRAINT preparaciones_check_2 CHECK (costo_total >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('preparaciones');
    }
};
