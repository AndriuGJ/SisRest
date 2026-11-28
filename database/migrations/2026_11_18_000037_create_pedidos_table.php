<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->enum('modalidad', ['mesa', 'rapida', 'delivery']);
            $table->foreignId('mesa_id')->nullable()->constrained('mesas')->restrictOnDelete();
            $table->foreignId('mozo_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $table->foreignId('creado_por')->constrained('users')->restrictOnDelete();
            $table->enum('estado', ['abierto', 'en_preparacion', 'listo', 'entregado', 'cerrado', 'anulado'])->default('abierto');
            $table->unsignedSmallInteger('comensales')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamp('abierto_at')->index();
            $table->timestamp('cerrado_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pedidos ADD CONSTRAINT pedidos_check_1 CHECK ((modalidad = 'mesa' AND mesa_id IS NOT NULL AND mozo_id IS NOT NULL) OR (modalidad <> 'mesa' AND mesa_id IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
