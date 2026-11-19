<?php

use App\Livewire\Cobros\HistorialCobros;
use App\Livewire\Devoluciones\DevolucionFormulario;
use App\Livewire\Devoluciones\DevolucionPantalla;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\Devolucion;
use App\Models\DevolucionPago;
use App\Models\EnvioCocinaDetalle;
use App\Models\MedioPago;
use App\Models\Mesa;
use App\Models\MotivoBeneficio;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\ResolucionDevolucion;
use App\Models\ResolucionDevolucionDetalle;
use App\Models\Salon;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Cajas\GestionCajas;
use App\Services\Cajas\ResumenTurno;
use App\Services\Cobros\CerrarAtencion;
use App\Services\Cobros\EstadoCuenta;
use App\Services\Cobros\GestionCobros;
use App\Services\Cocina\GestionCocina;
use App\Services\Devoluciones\GestionDevoluciones;
use App\Services\Devoluciones\ResolverProductosDevueltos;
use App\Services\Devoluciones\SaldoDevolucion;
use App\Services\Pos\ConfirmarPedido;
use App\Services\Pos\GestionEntregas;
use App\Services\Pos\GestionPedidos;
use Database\Seeders\EjemploInventarioSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([RolesPermisosSeeder::class, EjemploInventarioSeeder::class]);
    $this->actor = User::factory()->create();
    $this->actor->assignRole('administrador');
    $this->actingAs($this->actor);
    $this->turno = app(GestionCajas::class)->abrir($this->actor, Caja::firstOrFail()->id, $this->actor->id, '100', '', (string) Str::uuid());
    $this->chaufa = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    $this->gaseosa = Producto::where('codigo', 'EJ-GASEOSA')->firstOrFail();
    $this->combo = Producto::where('tipo', 'combo')->firstOrFail();
    $this->chaufa->articulo->update(['stock_actual' => '20']);
    $this->gaseosa->articulo->update(['stock_actual' => '20']);
});

function cuentaDevolucion(User $actor, TurnoCaja $turno, array $productos, ?Mesa $mesa = null, ?string $envio = null): Pedido
{
    $pedido = app(GestionPedidos::class)->abrir($actor, $turno->id, (string) Str::uuid(), ['modalidad' => $envio !== null ? 'delivery' : ($mesa ? 'mesa' : 'rapida'),
        'mesaId' => $mesa ? (string) $mesa->id : '', 'mozoId' => (string) $actor->id, 'comensales' => '', 'observaciones' => '', 'destinatario' => 'Cliente delivery', 'telefono' => '999888777', 'direccion' => 'Av. Prueba 123', 'referencia' => '', 'costoEnvio' => $envio ?? '0'])->refresh();
    $filas = [];
    foreach ($productos as $id => $cantidad) {
        $filas['n-'.$id] = ['detalleId' => '', 'productoId' => (string) $id, 'cantidad' => (string) $cantidad, 'nota' => ''];
    }
    app(GestionPedidos::class)->guardar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido), ['clienteId' => '', 'comensales' => '', 'observaciones' => ''], $filas);
    app(ConfirmarPedido::class)->confirmar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido->fresh()), (string) Str::uuid());

    return $pedido->fresh();
}
function seleccionDevolucion(Pedido $pedido, ?array $porProducto = null): array
{
    $filas = [];
    foreach ($pedido->detalles()->whereNull('detalle_padre_id')->where('estado', 'confirmado')->get() as $detalle) {
        $cantidad = $porProducto === null ? bcsub($detalle->cantidad, $detalle->cantidad_cobrada, 0) : (string) ($porProducto[$detalle->producto_id] ?? '0');
        if ($cantidad !== '0') {
            $filas['d-'.$detalle->id] = ['detalleId' => (string) $detalle->id, 'cantidad' => $cantidad];
        }
    }

    return $filas;
}
function pagoDevolucion(string $codigo, string $monto, string $entregado = '', string $referencia = ''): array
{
    return ['medioId' => (string) MedioPago::where('codigo', $codigo)->firstOrFail()->id, 'monto' => $monto, 'entregado' => $entregado, 'referencia' => $referencia];
}
function datosDevolucion(array $cambios = []): array
{
    return array_replace(['clienteId' => '', 'grupoPagador' => '', 'incluirEnvio' => false], $cambios);
}
function realizarCobroDev(User $actor, Pedido $pedido, TurnoCaja $turno, array $seleccion, array $pagos, array $datos = []): Cobro
{
    $datos = datosDevolucion($datos);
    $plan = app(GestionCobros::class)->previsualizar($actor, $pedido->id, $turno->id, $datos, $seleccion, $pagos);

    return app(GestionCobros::class)->cobrar($actor, $pedido->id, $turno->id, (string) Str::uuid(), $plan['huella'], $datos, $seleccion, $pagos);
}

function reintegroDev(User $actor, Cobro $cobro, TurnoCaja $turno, ?array $seleccion = null, ?array $montos = null, bool $envio = false): Devolucion
{
    $saldo = SaldoDevolucion::calcular($cobro);
    $seleccion ??= collect($saldo['lineas'])->filter(fn ($d) => bccomp($d['disponible'], '0', 4) > 0)->mapWithKeys(fn ($d) => ['d-'.$d['id'] => ['detalleId' => (string) $d['id'], 'cantidad' => bcadd($d['disponible'], '0', 0)]])->all();
    $pagos = [];
    foreach ($saldo['pagos'] as $pago) {
        $monto = $montos === null ? $pago['disponible'] : ($montos[$pago['id']] ?? '0');
        $pagos['p-'.$pago['id']] = ['pagoId' => (string) $pago['id'], 'monto' => $monto, 'referencia' => 'OPERACION-DEV'];
    }
    $s = app(GestionDevoluciones::class);
    $plan = $s->previsualizar($actor, $cobro->id, $turno->id, $seleccion, $pagos, $envio, 'Devolución solicitada por el cliente');

    return $s->devolver($actor, $cobro->id, $turno->id, $seleccion, $pagos, $envio, 'Devolución solicitada por el cliente', (string) Str::uuid(), $plan['huella']);
}
function fichaDev(User $actor, TurnoCaja $turno, Producto $producto, string $q = '1', string $medio = 'efectivo', array $extra = [], ?string $envio = null): Cobro
{
    $pedido = cuentaDevolucion($actor, $turno, [$producto->id => $q], envio: $envio);
    $total = bcadd(bcmul($producto->precio_venta, $q, 2), $envio ?? '0', 2);

    return realizarCobroDev($actor, $pedido, $turno, seleccionDevolucion($pedido), $total === '0.00' ? [] : ['p' => pagoDevolucion($medio, $total)], array_replace(['incluirEnvio' => $envio !== null], $extra));
}
function resolverDev(User $actor, Devolucion $devolucion, string $accion, array $destinos = [], string $q = '1'): ResolucionDevolucion
{
    $detalle = $devolucion->detalles()->firstOrFail();
    $s = app(ResolverProductosDevueltos::class);
    $plan = $s->previsualizar($actor, $detalle->id, $q, $accion, $destinos, 'Decisión autorizada sobre el producto');

    return $s->resolver($actor, $detalle->id, $q, $accion, $destinos, 'Decisión autorizada sobre el producto', (string) Str::uuid(), $plan['huella']);
}
function entregarDev(User $actor, Cobro $cobro): void
{
    foreach ($cobro->pedido->detalles()->with('producto')->get() as $hoja) {
        if ($hoja->producto->tipo->value === 'combo') {
            continue;
        }
        foreach (EnvioCocinaDetalle::where('pedido_detalle_id', $hoja->id)->get() as $envio) {
            foreach (['iniciar', 'listo'] as $accion) {
                app(GestionCocina::class)->avanzar($actor, $envio->id, $accion, bcadd($hoja->cantidad, '0', 0), GestionCocina::huella($envio->fresh()));
            }
        }
        app(GestionEntregas::class)->entregar($actor, $cobro->pedido_id, $hoja->id, bcadd($hoja->cantidad, '0', 0), GestionPedidos::huella($cobro->pedido->fresh()));
    }
}

test('a refund records only applied cash excluding change and leaves stock and original receipt immutable', function () {
    $pedido = cuentaDevolucion($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    $cobro = realizarCobroDev($this->actor, $pedido, $this->turno, seleccionDevolucion($pedido), ['p' => pagoDevolucion('efectivo', '6', '20')]);
    $antes = $cobro->fresh()->getAttributes();
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    expect($dev->total)->toBe('6.00')->and($cobro->fresh()->getAttributes())->toBe($antes)->and($cobro->pagos()->first()->monto_entregado)->toBe('20.00')->and($pedido->detalles()->first()->cantidad_cobrada)->toBe('1.0000')->and($this->gaseosa->articulo->fresh()->stock_actual)->toBe('20.0000')->and($this->gaseosa->articulo->fresh()->stock_reservado)->toBe('1.0000')->and($this->turno->efectivoEsperado())->toBe('100.00')->and(MovimientoCaja::where('origen_type', DevolucionPago::class)->count())->toBe(1)->and($dev->detalles()->first()->cantidad_resuelta)->toBe('0.0000');
});

test('partial refunds use net original discounts and allocate every cent exactly once', function () {
    $pedido = cuentaDevolucion($this->actor, $this->turno, [$this->gaseosa->id => 3]);
    $motivo = MotivoBeneficio::whereIn('tipo', ['descuento', 'ambos'])->firstOrFail();
    $cobro = realizarCobroDev($this->actor, $pedido, $this->turno, seleccionDevolucion($pedido), ['p' => pagoDevolucion('yape', '17')], ['descuentoTipo' => 'soles', 'descuentoValor' => '1', 'motivoId' => (string) $motivo->id, 'motivoDetalle' => 'Promoción autorizada especial']);
    $linea = $cobro->detalles()->firstOrFail();
    $pago = $cobro->pagos()->firstOrFail();
    $ids = ['x' => ['detalleId' => (string) $linea->id, 'cantidad' => '1']];
    $importes = [];
    foreach (['5.67', '5.67', '5.66'] as $importe) {
        $importes[] = reintegroDev($this->actor, $cobro, $this->turno, $ids, [$pago->id => $importe])->total;
    }
    expect($importes)->toBe(['5.67', '5.67', '5.66'])->and(SaldoDevolucion::calcular($cobro)['disponible'])->toBe('0.00')->and($this->turno->efectivoEsperado())->toBe('100.00');
    expect(fn () => reintegroDev($this->actor, $cobro, $this->turno, $ids, [$pago->id => '5.67']))->toThrow(ValidationException::class);
});

test('combined refunds preserve each original payment medium and its historical cash flag', function () {
    $pedido = cuentaDevolucion($this->actor, $this->turno, [$this->gaseosa->id => 2]);
    $cobro = realizarCobroDev($this->actor, $pedido, $this->turno, seleccionDevolucion($pedido), ['a' => pagoDevolucion('efectivo', '5', '20'), 'b' => pagoDevolucion('yape', '7')]);
    MedioPago::where('codigo', 'efectivo')->update(['nombre' => 'Renombrado', 'afecta_efectivo' => false, 'activo' => false]);
    MedioPago::where('codigo', 'yape')->update(['afecta_efectivo' => true]);
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    expect($dev->pagos()->orderBy('id')->pluck('medio_pago_nombre')->all())->toBe(['Efectivo', 'Yape'])->and($this->turno->efectivoEsperado())->toBe('100.00')->and(MovimientoCaja::where('tipo', 'egreso')->sum('monto'))->toEqual(5);
    $resumen = app(ResumenTurno::class)->calcular($this->turno);
    expect($resumen['cobrado'])->toBe('12.00')->and($resumen['devuelto'])->toBe('12.00')->and($resumen['neto_cobrado'])->toBe('0.00');
});

test('shipping can be refunded separately only once and does not become new debt', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa, envio: '5');
    $p = $cobro->pagos()->firstOrFail();
    $dev = reintegroDev($this->actor, $cobro, $this->turno, [], [$p->id => '5'], true);
    expect($dev->cargo_envio)->toBe('5.00')->and($dev->detalles()->count())->toBe(0)->and(EstadoCuenta::envioPendiente($cobro->pedido))->toBe('0.00');
    expect(fn () => reintegroDev($this->actor, $cobro, $this->turno, [], [], true))->toThrow(ValidationException::class);
    expect(reintegroDev($this->actor, $cobro, $this->turno)->total)->toBe('6.00');
});

test('a zero courtesy refund has quantities without fictitious payments and can cancel its pending delivery', function () {
    $this->gaseosa->update(['precio_venta' => '0']);
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    expect($dev->total)->toBe('0.00')->and($dev->pagos()->count())->toBe(0)->and(MovimientoCaja::count())->toBe(0);
    resolverDev($this->actor, $dev, 'cancelar_pendiente');
    expect($this->gaseosa->articulo->fresh()->stock_actual)->toBe('20.0000')->and($this->gaseosa->articulo->fresh()->stock_reservado)->toBe('0.0000');
});

test('refunds from closed original shifts affect only the current open shift', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    $nuevo = app(GestionCajas::class)->abrir($this->actor, $this->turno->caja_id, $this->actor->id, '20', '', (string) Str::uuid());
    $dev = reintegroDev($this->actor, $cobro, $nuevo);
    expect($dev->turno_caja_id)->toBe($nuevo->id)->and($nuevo->efectivoEsperado())->toBe('14.00')->and($this->turno->efectivoEsperado())->toBe('106.00')->and(app(ResumenTurno::class)->calcular($nuevo)['neto_cobrado'])->toBe('-6.00');
});

test('cash availability and the separate expense permission are enforced but digital refunds need no expense permission', function () {
    $cash = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $digital = fichaDev($this->actor, $this->turno, $this->gaseosa, medio: 'yape');
    $actor = User::factory()->create();
    $actor->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'cajas.ver_todas', 'devoluciones.crear']);
    expect(fn () => reintegroDev($actor, $cash, $this->turno))->toThrow(AuthorizationException::class);
    expect(reintegroDev($actor, $digital, $this->turno)->total)->toBe('6.00');
    $this->turno->update(['fondo_inicial' => '0']);
    app(GestionCajas::class)->movimiento($this->actor, $this->turno->id, (string) Str::uuid(), app(ResumenTurno::class)->calcular($this->turno)['huella'], ['tipo' => 'retiro', 'concepto' => 'Retiro de prueba', 'monto' => '6', 'motivo' => 'Fondo agotado']);
    expect(fn () => reintegroDev($this->actor, $cash, $this->turno))->toThrow(ValidationException::class);
});

test('a fresh required refund operation reference is validated', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa, medio: 'yape');
    MedioPago::where('codigo', 'yape')->update(['requiere_referencia' => true]);
    $linea = $cobro->detalles()->firstOrFail();
    $pago = $cobro->pagos()->firstOrFail();
    expect(fn () => app(GestionDevoluciones::class)->previsualizar($this->actor, $cobro->id, $this->turno->id, ['x' => ['detalleId' => (string) $linea->id, 'cantidad' => '1']], ['p' => ['pagoId' => (string) $pago->id, 'monto' => '6', 'referencia' => '']], false, 'Prueba referencia'))->toThrow(ValidationException::class);
});

test('cashiers require refund permission original and current box scope and active status', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $actor = User::factory()->create();
    $actor->assignRole('cajero');
    $this->actingAs($actor)->get(route('pos.devolver', $cobro))->assertForbidden();
    $actor->givePermissionTo('devoluciones.crear');
    expect(fn () => reintegroDev($actor, $cobro, $this->turno))->toThrow(HttpException::class);
    $actor->givePermissionTo('cajas.ver_todas');
    $actor->update(['activo' => false]);
    expect(fn () => reintegroDev($actor, $cobro, $this->turno))->toThrow(HttpException::class);
});

test('quantity payment tampering and unknown original lines cannot exceed refundable amounts', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $otro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $l = $cobro->detalles()->firstOrFail();
    $p = $cobro->pagos()->firstOrFail();
    foreach ([['detalleId' => (string) $l->id, 'cantidad' => '2'], ['detalleId' => (string) $l->id, 'cantidad' => '0.5'], ['detalleId' => (string) $otro->detalles()->first()->id, 'cantidad' => '1'], ['detalleId' => (string) $l->id, 'cantidad' => '1', 'precio' => '1']] as $fila) {
        expect(fn () => reintegroDev($this->actor, $cobro, $this->turno, ['x' => $fila], [$p->id => '6']))->toThrow(ValidationException::class);
    }
    expect(fn () => reintegroDev($this->actor, $cobro, $this->turno, null, [$p->id => '20']))->toThrow(ValidationException::class);
    expect(Devolucion::count())->toBe(0);
});

test('refund retry after closure returns the same operation and a changed UUID or actor cannot duplicate it', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $l = $cobro->detalles()->first();
    $p = $cobro->pagos()->first();
    $s = app(GestionDevoluciones::class);
    $sel = ['x' => ['detalleId' => (string) $l->id, 'cantidad' => '1']];
    $pag = ['p' => ['pagoId' => (string) $p->id, 'monto' => '6', 'referencia' => '']];
    $clave = (string) Str::uuid();
    $plan = $s->previsualizar($this->actor, $cobro->id, $this->turno->id, $sel, $pag, false, 'Prueba repetida');
    $dev = $s->devolver($this->actor, $cobro->id, $this->turno->id, $sel, $pag, false, 'Prueba repetida', $clave, $plan['huella']);
    expect(fn () => $s->devolver($this->actor, $cobro->id, $this->turno->id, $sel, $pag, false, 'Prueba repetida', (string) Str::uuid(), $plan['huella']))->toThrow(ValidationException::class);
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    expect($s->devolver($this->actor, $cobro->id, $this->turno->id, $sel, $pag, false, 'Prueba repetida', $clave, $plan['huella'])->id)->toBe($dev->id)->and(Devolucion::count())->toBe(1);
});

test('financial refund cannot proceed from a closed current shift and stale review checks cash and reference configuration', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $l = $cobro->detalles()->first();
    $p = $cobro->pagos()->first();
    $s = app(GestionDevoluciones::class);
    $sel = ['x' => ['detalleId' => (string) $l->id, 'cantidad' => '1']];
    $pag = ['p' => ['pagoId' => (string) $p->id, 'monto' => '6', 'referencia' => 'OK']];
    $plan = $s->previsualizar($this->actor, $cobro->id, $this->turno->id, $sel, $pag, false, 'Prueba revisada');
    MedioPago::where('id', $p->medio_pago_id)->update(['requiere_referencia' => true]);
    expect(fn () => $s->devolver($this->actor, $cobro->id, $this->turno->id, $sel, $pag, false, 'Prueba revisada', (string) Str::uuid(), $plan['huella']))->toThrow(ValidationException::class);
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    expect(fn () => reintegroDev($this->actor, $cobro, $this->turno))->toThrow(ValidationException::class);
});

test('unstarted paid cancellation releases stock reserve and permits manual close without creating a new debt', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    resolverDev($this->actor, $dev, 'cancelar_pendiente');
    $d = $cobro->pedido->detalles()->first();
    expect($d->cantidad_cancelada_pagada)->toBe('1.0000')->and($d->cantidad_cobrada)->toBe('1.0000')->and($d->cantidad_anulada)->toBe('0.0000')->and(EstadoCuenta::liquidada($cobro->pedido->fresh()))->toBeTrue()->and($this->gaseosa->articulo->fresh()->stock_reservado)->toBe('0.0000');
    app(CerrarAtencion::class)->cerrar($this->actor, $cobro->pedido_id, EstadoCuenta::huella($cobro->pedido->fresh()));
    expect($cobro->pedido->fresh()->estado->value)->toBe('cerrado');
});

test('started paid cancellation consumes waste once and blocks later kitchen progress or delivery', function () {
    $this->chaufa->articulo->update(['stock_actual' => '1']);
    $cobro = fichaDev($this->actor, $this->turno, $this->chaufa);
    $envio = EnvioCocinaDetalle::where('pedido_detalle_id', $cobro->detalles()->first()->pedido_detalle_id)->first();
    app(GestionCocina::class)->avanzar($this->actor, $envio->id, 'iniciar', '1', GestionCocina::huella($envio));
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    resolverDev($this->actor, $dev, 'cancelar_pendiente');
    expect($this->chaufa->articulo->fresh()->stock_actual)->toBe('0.0000')->and($this->chaufa->articulo->fresh()->stock_reservado)->toBe('0.0000')->and(MovimientoInventario::where('tipo', 'merma')->count())->toBe(1)->and($envio->fresh()->cantidad_anulada)->toBe('1.0000');
    expect(fn () => app(GestionCocina::class)->avanzar($this->actor, $envio->id, 'listo', '1', GestionCocina::huella($envio->fresh())))->toThrow(ValidationException::class);
    expect(fn () => app(GestionEntregas::class)->entregar($this->actor, $cobro->pedido_id, $envio->pedido_detalle_id, '1', GestionPedidos::huella($cobro->pedido->fresh())))->toThrow(ValidationException::class);
});

test('delivered reventa returns at its original outbound cost and updates weighted average only once', function () {
    $this->gaseosa->articulo->update(['stock_actual' => '2', 'costo_promedio' => '2']);
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    entregarDev($this->actor, $cobro);
    $this->gaseosa->articulo->update(['costo_promedio' => '4']);
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    $hoja = $cobro->pedido->detalles()->first();
    resolverDev($this->actor, $dev, 'retorno', [$hoja->id => 'reingreso']);
    expect($this->gaseosa->articulo->fresh()->stock_actual)->toBe('2.0000')->and($this->gaseosa->articulo->fresh()->costo_promedio)->toBe('3.000000')->and(MovimientoInventario::where('tipo', 'reingreso')->first()->costo_unitario)->toBe('2.000000')->and($hoja->fresh()->cantidad_devuelta)->toBe('1.0000');
    expect(fn () => resolverDev($this->actor, $dev, 'retorno', [$hoja->id => 'reingreso']))->toThrow(ValidationException::class);
});

test('returned prepared dishes cannot reenter sale stock and waste does not consume stock twice', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->chaufa);
    entregarDev($this->actor, $cobro);
    $hoja = $cobro->pedido->detalles()->first();
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    expect(fn () => resolverDev($this->actor, $dev, 'retorno', [$hoja->id => 'reingreso']))->toThrow(ValidationException::class);
    resolverDev($this->actor, $dev, 'retorno', [$hoja->id => 'merma']);
    expect($this->chaufa->articulo->fresh()->stock_actual)->toBe('19.0000')->and(MovimientoInventario::where('tipo', 'merma')->count())->toBe(0)->and(ResolucionDevolucionDetalle::first()->destino)->toBe('merma');
});

test('one monetary refund can resolve quantities partly as pending cancellation and partly as physical return', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa, '2');
    $hoja = $cobro->pedido->detalles()->first();
    app(GestionEntregas::class)->entregar($this->actor, $cobro->pedido_id, $hoja->id, '1', GestionPedidos::huella($cobro->pedido->fresh()));
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    resolverDev($this->actor, $dev, 'cancelar_pendiente');
    resolverDev($this->actor, $dev, 'retorno', [$hoja->id => 'sin_reingreso']);
    expect($dev->detalles()->first()->cantidad_resuelta)->toBe('2.0000')->and($hoja->fresh()->cantidad_cancelada_pagada)->toBe('1.0000')->and($hoja->fresh()->cantidad_devuelta)->toBe('1.0000')->and($this->gaseosa->articulo->fresh()->stock_actual)->toBe('19.0000')->and($this->gaseosa->articulo->fresh()->stock_reservado)->toBe('0.0000');
});

test('combo returns use immutable component factors and allow different stock destinations', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->combo);
    entregarDev($this->actor, $cobro);
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    $raiz = $cobro->pedido->detalles()->whereNull('detalle_padre_id')->first();
    $destinos = [];
    foreach ($raiz->hijos()->with('producto.articulo')->get() as $hijo) {
        $destinos[$hijo->id] = $hijo->producto->articulo->tipo->value === 'reventa' ? 'reingreso' : 'merma';
    }
    resolverDev($this->actor, $dev, 'retorno', $destinos);
    expect($raiz->fresh()->cantidad_devuelta)->toBe('1.0000')->and($this->chaufa->articulo->fresh()->stock_actual)->toBe('19.0000')->and($this->gaseosa->articulo->fresh()->stock_actual)->toBe('20.0000');
});

test('refund of a closed attention never reopens it or changes table occupation', function () {
    $mesa = Mesa::create(['salon_id' => Salon::first()->id, 'codigo' => 'DEV-1']);
    $pedido = cuentaDevolucion($this->actor, $this->turno, [$this->gaseosa->id => 1], $mesa);
    $cobro = realizarCobroDev($this->actor, $pedido, $this->turno, seleccionDevolucion($pedido), ['p' => pagoDevolucion('efectivo', '6')]);
    entregarDev($this->actor, $cobro);
    app(CerrarAtencion::class)->cerrar($this->actor, $pedido->id, EstadoCuenta::huella($pedido->fresh()));
    $otro = cuentaDevolucion($this->actor, $this->turno, [$this->gaseosa->id => 1], $mesa);
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    $hoja = $pedido->detalles()->first();
    resolverDev($this->actor, $dev, 'retorno', [$hoja->id => 'reingreso']);
    expect($pedido->fresh()->estado->value)->toBe('cerrado')->and(OcupacionMesa::where('mesa_id', $mesa->id)->first()->pedido_id)->toBe($otro->id);
});

test('money-only resolution keeps delivery unchanged and requires no inventory permission', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    $actor = User::factory()->create();
    $actor->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'cajas.ver_todas', 'devoluciones.crear']);
    resolverDev($actor, $dev, 'mantener');
    expect($cobro->pedido->detalles()->first()->cantidad_cancelada_pagada)->toBe('0.0000')->and($this->gaseosa->articulo->fresh()->stock_reservado)->toBe('1.0000')->and(ResolucionDevolucionDetalle::count())->toBe(0);
    expect(fn () => resolverDev($actor, $dev, 'mantener'))->toThrow(ValidationException::class);
});

test('inventory resolution enforces extra permissions and cannot cancel what was already served', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    entregarDev($this->actor, $cobro);
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    $hoja = $cobro->pedido->detalles()->first();
    expect(fn () => resolverDev($this->actor, $dev, 'cancelar_pendiente'))->toThrow(ValidationException::class);
    $actor = User::factory()->create();
    $actor->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'cajas.ver_todas', 'devoluciones.crear']);
    expect(fn () => resolverDev($actor, $dev, 'retorno', [$hoja->id => 'reingreso']))->toThrow(AuthorizationException::class);
    expect(fn () => resolverDev($actor, $dev, 'retorno', [$hoja->id => 'merma']))->toThrow(AuthorizationException::class);
    expect(fn () => resolverDev($actor, $dev, 'cancelar_pendiente'))->toThrow(AuthorizationException::class);
});

test('stale physical-return review and missing outbound trace cannot fabricate stock', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    entregarDev($this->actor, $cobro);
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    $d = $dev->detalles()->first();
    $hoja = $cobro->pedido->detalles()->first();
    $s = app(ResolverProductosDevueltos::class);
    $dest = [$hoja->id => 'reingreso'];
    $plan = $s->previsualizar($this->actor, $d->id, '1', 'retorno', $dest, 'Retorno revisado');
    $this->gaseosa->articulo->update(['costo_promedio' => '8']);
    expect(fn () => $s->resolver($this->actor, $d->id, '1', 'retorno', $dest, 'Retorno revisado', (string) Str::uuid(), $plan['huella']))->toThrow(ValidationException::class);
    MovimientoInventario::where('tipo', 'entrega')->delete();
    expect(fn () => resolverDev($this->actor, $dev, 'retorno', $dest))->toThrow(ValidationException::class);
    expect(MovimientoInventario::where('tipo', 'reingreso')->count())->toBe(0);
});

test('cash close review includes digital refunds and immutable closure stores the new summary', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa, medio: 'yape');
    $antes = app(ResumenTurno::class)->calcular($this->turno);
    reintegroDev($this->actor, $cobro, $this->turno);
    $despues = app(ResumenTurno::class)->calcular($this->turno);
    expect($despues['huella'])->not->toBe($antes['huella'])->and($despues['esperado'])->toBe('100.00')->and($despues['devuelto'])->toBe('6.00');
    $this->get(route('cajas.turno', $this->turno))->assertOk()->assertSee('Devoluciones registradas en este turno')->assertSee('6.00');
});

test('Livewire financial review is locked and confirms the reviewed values after form changes', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa, '2');
    $l = $cobro->detalles()->first();
    $p = $cobro->pagos()->first();
    $pantalla = Livewire::test(DevolucionFormulario::class, ['cobro' => $cobro]);
    $pantalla->set('cantidades.'.$l->id, '1')->set('pagos.p-'.$p->id.'.monto', '6')->set('motivo', 'Devolución revisada')->call('review')->assertDispatched('app:confirm')->set('cantidades.'.$l->id, '2')->set('pagos.p-'.$p->id.'.monto', '12')->call('confirmRefund');
    $dev = Devolucion::firstOrFail();
    expect($dev->total)->toBe('6.00');
    $pantalla->assertRedirect(route('pos.devolucion', $dev));
    expect(fn () => Livewire::test(DevolucionFormulario::class, ['cobro' => $cobro])->set('turnoRevisado', 999))->toThrow(CannotUpdateLockedPropertyException::class);
    Livewire::test(DevolucionFormulario::class, ['cobro' => $cobro])->set('cantidades.'.$l->id, ['invalido'])->call('review')->assertHasErrors();
});

test('Livewire physical resolution uses locked reviewed data and exposes no stock costs', function () {
    $this->gaseosa->articulo->update(['costo_promedio' => '2.123456']);
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    entregarDev($this->actor, $cobro);
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    $d = $dev->detalles()->first();
    $hoja = $cobro->pedido->detalles()->first();
    $ui = Livewire::test(DevolucionPantalla::class, ['devolucion' => $dev])->call('selectProduct', $d->id)->set('accion', 'retorno')->set('destinos.'.$hoja->id, 'reingreso')->set('motivo', 'Retorno de envase sellado')->call('reviewProducts')->assertDispatched('app:confirm')->assertDontSee('2.123456')->set('accion', 'mantener')->call('confirmProducts')->assertDispatched('app:alert');
    expect($this->gaseosa->articulo->fresh()->stock_actual)->toBe('20.0000')->and(ResolucionDevolucion::first()->accion)->toBe('retorno');
    expect(fn () => $ui->set('detalleId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('refund receipt links and product actions are scoped to both cash boxes', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    $this->get(route('pos.cobro', $cobro))->assertOk()->assertSee('Devolver productos / pago')->assertSee($dev->codigo);
    $this->get(route('pos.devolver', $cobro))->assertOk();
    $this->get(route('pos.devolucion', $dev))->assertOk()->assertSee('Resolver producto');
    $actor = User::factory()->create();
    $actor->assignRole('cajero');
    $actor->givePermissionTo('devoluciones.crear');
    $this->actingAs($actor)->get(route('pos.devolucion', $dev))->assertForbidden();
});

test('searchable receipt history includes closed attentions and respects current order and box scope', function () {
    $cobro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    entregarDev($this->actor, $cobro);
    app(CerrarAtencion::class)->cerrar($this->actor, $cobro->pedido_id, EstadoCuenta::huella($cobro->pedido->fresh()));
    $dev = reintegroDev($this->actor, $cobro, $this->turno);
    $otro = fichaDev($this->actor, $this->turno, $this->gaseosa);
    $this->get(route('pos.index'))->assertOk()->assertSee('Historial de cobros');
    Livewire::test(HistorialCobros::class)->set('search', $cobro->codigo)->assertSee($cobro->codigo)->assertDontSee($otro->codigo)->set('search', '')->set('devolucionFilter', 'con')->assertSee($cobro->codigo)->assertDontSee($otro->codigo);
    $actor = User::factory()->create();
    $actor->assignRole('cajero');
    $this->actingAs($actor);
    Livewire::test(HistorialCobros::class)->assertDontSee($cobro->codigo)->assertDontSee($otro->codigo);
    $actor->givePermissionTo('cajas.ver_todas');
    Livewire::test(HistorialCobros::class)->assertSee($cobro->codigo);
    $actor->update(['activo' => false]);
    $this->get(route('pos.cobros'))->assertRedirect(route('login'));
});
