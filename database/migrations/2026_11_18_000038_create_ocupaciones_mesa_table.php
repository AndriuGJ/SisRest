<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocupaciones_mesa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mesa_id')->constrained('mesas')->restrictOnDelete();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $table->unique('mesa_id');
            $table->unique('pedido_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocupaciones_mesa');
    }
};
