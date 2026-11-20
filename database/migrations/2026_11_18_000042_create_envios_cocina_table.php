<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envios_cocina', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $table->foreignId('area_preparacion_id')->constrained('areas_preparacion')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('numero_envio');
            $table->timestamp('enviado_at')->index();
            $table->unique(['pedido_id', 'area_preparacion_id', 'numero_envio'], 'envios_cocina_numero_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envios_cocina');
    }
};
