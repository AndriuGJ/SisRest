<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('tipo_comprobante_id')->nullable()->constrained('tipos_comprobante')->restrictOnDelete();
            $table->string('serie', 10)->nullable();
            $table->string('numero', 30)->nullable();
            $table->date('fecha')->index();
            $table->date('fecha_vencimiento')->nullable();
            $table->enum('condicion_pago', ['contado', 'credito'])->default('contado');
            $table->enum('estado', ['borrador', 'confirmada', 'anulada'])->default('borrador');
            $table->string('moneda', 3);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('impuestos', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('confirmada_at')->nullable();
            $table->text('observaciones')->nullable();
            $table->unique(['proveedor_id', 'tipo_comprobante_id', 'serie', 'numero'], 'compras_documento_unique');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE compras ADD CONSTRAINT compras_check_1 CHECK (subtotal >= 0 AND descuento >= 0 AND impuestos >= 0 AND total >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
