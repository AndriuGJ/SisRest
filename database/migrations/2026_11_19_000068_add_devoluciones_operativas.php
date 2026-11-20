<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devoluciones', function (Blueprint $t) {
            $t->decimal('cargo_envio', 14, 2)->default(0);
            $t->string('usuario_nombre')->nullable();
            $t->string('caja_nombre')->nullable();
        });
        Schema::table('devolucion_pagos', function (Blueprint $t) {
            $t->string('medio_pago_nombre')->nullable();
            $t->boolean('afecta_efectivo')->nullable();
        });
        Schema::table('devolucion_detalles', fn (Blueprint $t) => $t->decimal('cantidad_resuelta', 16, 4)->default(0));
        Schema::table('pedido_detalles', function (Blueprint $t) {
            $t->decimal('cantidad_cancelada_pagada', 16, 4)->default(0);
            $t->decimal('cantidad_devuelta', 16, 4)->default(0);
        });
        Schema::create('resoluciones_devolucion', function (Blueprint $t) {
            $t->id();
            $t->foreignId('devolucion_detalle_id')->constrained('devolucion_detalles')->restrictOnDelete();
            $t->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $t->string('usuario_nombre');
            $t->decimal('cantidad', 16, 4);
            $t->enum('accion', ['mantener', 'cancelar_pendiente', 'retorno']);
            $t->text('motivo');
            $t->uuid('clave_idempotencia')->unique();
            $t->timestamp('resuelta_at');
            $t->timestamps();
        });
        Schema::create('resolucion_devolucion_detalles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('resolucion_devolucion_id')->constrained('resoluciones_devolucion')->restrictOnDelete();
            $t->foreignId('pedido_detalle_id')->constrained('pedido_detalles')->restrictOnDelete();
            $t->foreignId('articulo_id')->constrained('articulos')->restrictOnDelete();
            $t->foreignId('movimiento_entrega_id')->nullable()->constrained('movimientos_inventario')->restrictOnDelete();
            $t->string('descripcion');
            $t->string('destino', 30);
            $t->decimal('cantidad', 16, 4);
            $t->decimal('costo_unitario', 18, 6);
            $t->json('avance_antes')->nullable();
            $t->json('avance_despues')->nullable();
            $t->timestamps();
        });
        if (DB::getDriverName() === 'mysql') {
            $this->checkTotal('total >= 0');
            DB::statement('ALTER TABLE devolucion_detalles ADD CONSTRAINT devolucion_resuelta_check CHECK (cantidad_resuelta >= 0 AND cantidad_resuelta <= cantidad)');
            DB::statement('ALTER TABLE pedido_detalles ADD CONSTRAINT pedido_devoluciones_check CHECK (cantidad_cancelada_pagada >= 0 AND cantidad_devuelta >= 0 AND cantidad_entregada + cantidad_anulada + cantidad_cancelada_pagada <= cantidad AND cantidad_devuelta <= cantidad_entregada AND cantidad_cancelada_pagada + cantidad_devuelta <= cantidad_cobrada)');
        }
    }

    private function dropCheck(string $table, string $name): void
    {
        $maria = str_contains(strtolower((string) DB::getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION)), 'maria');
        DB::statement('ALTER TABLE '.$table.' DROP '.($maria ? 'CONSTRAINT ' : 'CHECK ').$name);
    }

    private function checkTotal(string $expression): void
    {
        $this->dropCheck('devoluciones', 'devoluciones_check_1');
        DB::statement('ALTER TABLE devoluciones ADD CONSTRAINT devoluciones_check_1 CHECK ('.$expression.')');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->checkTotal('total > 0');
            $this->dropCheck('pedido_detalles', 'pedido_devoluciones_check');
            $this->dropCheck('devolucion_detalles', 'devolucion_resuelta_check');
        }
        Schema::dropIfExists('resolucion_devolucion_detalles');
        Schema::dropIfExists('resoluciones_devolucion');
        Schema::table('pedido_detalles', fn (Blueprint $t) => $t->dropColumn(['cantidad_cancelada_pagada', 'cantidad_devuelta']));
        Schema::table('devolucion_detalles', fn (Blueprint $t) => $t->dropColumn('cantidad_resuelta'));
        Schema::table('devolucion_pagos', fn (Blueprint $t) => $t->dropColumn(['medio_pago_nombre', 'afecta_efectivo']));
        Schema::table('devoluciones', fn (Blueprint $t) => $t->dropColumn(['cargo_envio', 'usuario_nombre', 'caja_nombre']));
    }
};
