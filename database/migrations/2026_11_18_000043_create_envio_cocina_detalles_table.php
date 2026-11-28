<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envio_cocina_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_cocina_id')->constrained('envios_cocina')->restrictOnDelete();
            $table->foreignId('pedido_detalle_id')->constrained('pedido_detalles')->restrictOnDelete();
            $table->string('descripcion', 200);
            $table->text('nota')->nullable();
            $table->decimal('cantidad', 16, 4);
            $table->decimal('cantidad_iniciada', 16, 4)->default(0);
            $table->decimal('cantidad_lista', 16, 4)->default(0);
            $table->decimal('cantidad_entregada', 16, 4)->default(0);
            $table->decimal('cantidad_anulada', 16, 4)->default(0);
            $table->unique(['envio_cocina_id', 'pedido_detalle_id'], 'envios_detalle_unique');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE envio_cocina_detalles ADD CONSTRAINT envio_cocina_detalles_check_1 CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE envio_cocina_detalles ADD CONSTRAINT envio_cocina_detalles_check_2 CHECK (cantidad_anulada >= 0 AND cantidad_iniciada >= 0 AND cantidad_iniciada + cantidad_anulada <= cantidad)');
            DB::statement('ALTER TABLE envio_cocina_detalles ADD CONSTRAINT envio_cocina_detalles_check_3 CHECK (cantidad_lista >= 0 AND cantidad_lista <= cantidad_iniciada)');
            DB::statement('ALTER TABLE envio_cocina_detalles ADD CONSTRAINT envio_cocina_detalles_check_4 CHECK (cantidad_entregada >= 0 AND cantidad_entregada <= cantidad_lista)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('envio_cocina_detalles');
    }
};
