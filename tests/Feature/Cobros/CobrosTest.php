<?php

use App\Livewire\Cobros\CobroDetallePantalla;
use App\Livewire\Cobros\CobroFormulario;
use App\Livewire\Pos\PedidoPantalla;
use App\Models\Articulo;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\EnvioCocina;
use App\Models\MedioPago;
use App\Models\Mesa;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\OcupacionMesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\ReservaInventario;
use App\Models\Salon;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Cajas\GestionCajas;
use App\Services\Cajas\ResumenTurno;
use App\Services\Cobros\CerrarAtencion;
use App\Services\Cobros\EstadoCuenta;
use App\Services\Cobros\GestionCobros;
use App\Services\Impresion\TransporteEscPos;
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
    foreach (Caja::with('impresora')->get() as $caja) {
        $caja->impresora->update(['host' => '127.0.0.1', 'puerto' => 19102 + $caja->id, 'activo' => true]);
    }
    $this->spy(TransporteEscPos::class);
    $this->turno = app(GestionCajas::class)->abrir($this->actor, Caja::firstOrFail()->id, $this->actor->id, '100', '', (string) Str::uuid());
    $this->chaufa = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    $this->gaseosa = Producto::where('codigo', 'EJ-GASEOSA')->firstOrFail();
    $this->combo = Producto::where('tipo', 'combo')->firstOrFail();
    $this->chaufa->articulo->update(['stock_actual' => '20']);
    $this->gaseosa->articulo->update(['stock_actual' => '20']);
});

function cuentaCobros(User $actor, TurnoCaja $turno, array $productos, ?Mesa $mesa = null, ?string $envio = null): Pedido
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
function seleccionCobros(Pedido $pedido, ?array $porProducto = null): array
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
function pagoCobros(string $codigo, string $monto, string $entregado = '', ?string $referencia = null): array
{
    return ['medioId' => (string) MedioPago::where('codigo', $codigo)->firstOrFail()->id, 'monto' => $monto, 'entregado' => $entregado, 'referencia' => $referencia ?? (in_array($codigo, ['yape', 'plin'], true) ? 'OP-PRUEBA' : '')];
}
function datosCobros(array $cambios = []): array
{
    return array_replace(['clienteId' => '', 'grupoPagador' => '', 'incluirEnvio' => false], $cambios);
}
function realizarCobro(User $actor, Pedido $pedido, TurnoCaja $turno, array $seleccion, array $pagos, array $datos = []): Cobro
{
    $datos = datosCobros($datos);
    $plan = app(GestionCobros::class)->previsualizar($actor, $pedido->id, $turno->id, $datos, $seleccion, $pagos);

    return app(GestionCobros::class)->cobrar($actor, $pedido->id, $turno->id, (string) Str::uuid(), $plan['huella'], $datos, $seleccion, $pagos);
}

test('combined payment applies only cash net of change and does not touch stock preparation or tax documents', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->chaufa->id => 4]);
    $antes = Articulo::orderBy('id')->get()->toArray();
    $reservas = ReservaInventario::get()->toArray();
    $cobro = realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '50', '100'), 'b' => pagoCobros('yape', '30', '', 'OP-123')]);
    expect($cobro->total)->toBe('80.00')->and($cobro->pagos()->count())->toBe(2)->and($cobro->detalles()->count())->toBe(1)
        ->and($this->turno->efectivoEsperado())->toBe('150.00')->and(MovimientoCaja::count())->toBe(1)
        ->and($cobro->pagos()->where('afecta_efectivo', true)->first()->vuelto)->toBe('50.00')->and($cobro->comprobante)->toBeNull()
        ->and(Articulo::orderBy('id')->get()->toArray())->toBe($antes)->and(ReservaInventario::get()->toArray())->toBe($reservas)->and(MovimientoInventario::count())->toBe(0);
    $resumen = app(ResumenTurno::class)->calcular($this->turno);
    expect($resumen['cobrado'])->toBe('80.00')->and($resumen['esperado'])->toBe('150.00')->and(array_column($resumen['medios'], 'monto'))->toBe(['50.00', '30.00'])
        ->and($pedido->detalles()->first()->cantidad_cobrada)->toBe('4.0000')->and($pedido->fresh()->estado->value)->toBe('en_preparacion');
    $this->get(route('pos.cobro', $cobro))->assertOk()->assertSee('OP-123')->assertSee('Vuelto: S/ 50.00');
    $this->get(route('cajas.turno', $this->turno))->assertOk()->assertSee('Cobro de venta');
});

test('families pay selected products and quantities across independent collections', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->chaufa->id => 3, $this->gaseosa->id => 2]);
    $uno = realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido, [$this->chaufa->id => 1, $this->gaseosa->id => 1]), ['a' => pagoCobros('yape', '26')], ['grupoPagador' => 'Familia Pérez']);
    expect($uno->total)->toBe('26.00')->and(EstadoCuenta::liquidada($pedido->fresh()))->toBeFalse();
    $dos = realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '46', '50')], ['grupoPagador' => 'Familia Díaz']);
    expect($dos->total)->toBe('46.00')->and(Cobro::count())->toBe(2)->and($pedido->detalles()->where('producto_id', $this->chaufa->id)->first()->cantidad_cobrada)->toBe('3.0000')
        ->and(EstadoCuenta::liquidada($pedido->fresh()))->toBeTrue()->and($this->turno->efectivoEsperado())->toBe('146.00');
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido->fresh()])->assertSee('Familia Pérez')->assertSee('Familia Díaz');
});

test('digital only payments do not create a physical cash movement', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '2'), 'b' => pagoCobros('plin', '4')]);
    expect(MovimientoCaja::count())->toBe(0)->and($this->turno->efectivoEsperado())->toBe('100.00')->and(Pago::count())->toBe(2);
});

test('delivery charge is taken once and can be collected alone after the products', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 2], envio: '5');
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '12')]);
    expect(EstadoCuenta::liquidada($pedido->fresh()))->toBeFalse()->and(EstadoCuenta::envioPendiente($pedido->fresh()))->toBe('5.00');
    $envio = realizarCobro($this->actor, $pedido, $this->turno, [], ['a' => pagoCobros('yape', '5')], ['incluirEnvio' => true]);
    expect($envio->detalles()->count())->toBe(0)->and($envio->cargo_envio)->toBe('5.00')->and($envio->total)->toBe('5.00')
        ->and(EstadoCuenta::envioPendiente($pedido->fresh()))->toBe('0.00')->and(EstadoCuenta::liquidada($pedido->fresh()))->toBeTrue();
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, [], ['a' => pagoCobros('yape', '5')], ['incluirEnvio' => true]))->toThrow(ValidationException::class);
    $this->get(route('pos.cobro', $envio))->assertOk()->assertSee('únicamente al envío');
});

test('shipping and selected products belong to the first payer without affecting the remainder', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 2], envio: '5');
    $cobro = realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido, [$this->gaseosa->id => 1]), ['a' => pagoCobros('efectivo', '11')], ['incluirEnvio' => true]);
    expect($cobro->total)->toBe('11.00')->and(EstadoCuenta::envioPendiente($pedido->fresh()))->toBe('0.00');
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '6')], ['incluirEnvio' => true]);
    expect(Cobro::sum('cargo_envio'))->toEqual(5)->and(Cobro::sum('total'))->toEqual(17);
});

test('combo is billed at its parent price and component paid counters follow historical factors', function () {
    $this->combo->componentes()->where('producto_id', $this->chaufa->id)->update(['cantidad' => '1.5']);
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->combo->id => 2]);
    $cobro = realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido, [$this->combo->id => 1]), ['a' => pagoCobros('efectivo', '25')]);
    $padre = $pedido->detalles()->whereNull('detalle_padre_id')->first();
    expect($cobro->detalles()->count())->toBe(1)->and($padre->cantidad_cobrada)->toBe('1.0000')->and($padre->hijos()->where('producto_id', $this->chaufa->id)->first()->cantidad_cobrada)->toBe('1.5000');
    $hijo = $padre->hijos()->first();
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, ['x' => ['detalleId' => (string) $hijo->id, 'cantidad' => '1']], ['a' => pagoCobros('efectivo', '25')]))->toThrow(ValidationException::class);
});

test('payment sum mismatch cash shortage digital change duplicate means and inactive means are rejected atomically', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $seleccion = seleccionCobros($pedido);
    foreach ([['a' => pagoCobros('efectivo', '19')], ['a' => pagoCobros('efectivo', '21')], ['a' => pagoCobros('efectivo', '20', '10')], ['a' => pagoCobros('yape', '20', '50')], ['a' => pagoCobros('efectivo', '10'), 'b' => pagoCobros('efectivo', '10')]] as $pagos) {
        expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, $seleccion, $pagos))->toThrow(ValidationException::class);
    }
    MedioPago::where('codigo', 'yape')->update(['activo' => false]);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, $seleccion, ['a' => pagoCobros('yape', '20')]))->toThrow(ValidationException::class);
    expect(Cobro::count())->toBe(0)->and(Pago::count())->toBe(0)->and(MovimientoCaja::count())->toBe(0)->and($pedido->detalles()->first()->cantidad_cobrada)->toBe('0.0000');
});

test('money precision invalid formats and fractional billed portions cannot enter a collection', function (string $valor) {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->chaufa->id => 1]);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', $valor)]))->toThrow(ValidationException::class);
    expect(Cobro::count())->toBe(0);
})->with(['0', '-20', '20.001', '2e1', '20,00', '1000000000000']);

test('unconfirmed foreign lines component prices extra fields and excess paid quantities cannot be charged', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $otro = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    $fila = array_values(seleccionCobros($pedido))[0];
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, ['x' => array_replace($fila, ['precio' => '1'])], ['a' => pagoCobros('efectivo', '1')]))->toThrow(ValidationException::class);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($otro), ['a' => pagoCobros('efectivo', '6')]))->toThrow(ValidationException::class);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, ['x' => array_replace($fila, ['cantidad' => '0.5'])], ['a' => pagoCobros('efectivo', '10')]))->toThrow(ValidationException::class);
    $linea = $pedido->detalles()->first();
    $linea->update(['estado' => 'borrador']);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, ['x' => $fila], ['a' => pagoCobros('efectivo', '20')]))->toThrow(ValidationException::class);
    $linea->update(['estado' => 'confirmado']);
    realizarCobro($this->actor, $pedido, $this->turno, ['x' => $fila], ['a' => pagoCobros('efectivo', '20')]);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, ['x' => $fila], ['a' => pagoCobros('efectivo', '20')]))->toThrow(ValidationException::class);
});

test('zero priced products are settled with no fictitious payment or cash movement', function () {
    $this->gaseosa->update(['precio_venta' => '0']);
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    $cobro = realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), []);
    expect($cobro->total)->toBe('0.00')->and($cobro->pagos()->count())->toBe(0)->and(MovimientoCaja::count())->toBe(0)->and(EstadoCuenta::liquidada($pedido->fresh()))->toBeTrue();
});

test('required operation reference is enforced from payment configuration', function () {
    MedioPago::where('codigo', 'yape')->update(['requiere_referencia' => true]);
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '6', '', '')]))->toThrow(ValidationException::class);
    $cobro = realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '6', '', 'YAPE-0001')]);
    expect($cobro->pagos()->first()->referencia)->toBe('YAPE-0001');
});

test('closed or inactive boxes reject new payments but retries preserve the original result', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 2]);
    $datos = datosCobros();
    $seleccion = seleccionCobros($pedido, [$this->gaseosa->id => 1]);
    $pagos = ['a' => pagoCobros('efectivo', '6')];
    $plan = app(GestionCobros::class)->previsualizar($this->actor, $pedido->id, $this->turno->id, $datos, $seleccion, $pagos);
    $clave = (string) Str::uuid();
    $cobro = app(GestionCobros::class)->cobrar($this->actor, $pedido->id, $this->turno->id, $clave, $plan['huella'], $datos, $seleccion, $pagos);
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    expect(app(GestionCobros::class)->cobrar($this->actor, $pedido->id, $this->turno->id, $clave, $plan['huella'], $datos, $seleccion, $pagos)->id)->toBe($cobro->id)->and(Cobro::count())->toBe(1)->and(MovimientoCaja::count())->toBe(1);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, $seleccion, $pagos))->toThrow(ValidationException::class);
    $this->turno->update(['estado' => 'abierta', 'cerrada_at' => null]);
    $this->turno->caja->update(['activo' => false]);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, $seleccion, $pagos))->toThrow(ValidationException::class);
});

test('reusing a payment key for another order is forbidden and stale review cannot charge twice', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 2]);
    $otro = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    $datos = datosCobros();
    $seleccion = seleccionCobros($pedido, [$this->gaseosa->id => 1]);
    $pagos = ['a' => pagoCobros('efectivo', '6')];
    $servicio = app(GestionCobros::class);
    $plan = $servicio->previsualizar($this->actor, $pedido->id, $this->turno->id, $datos, $seleccion, $pagos);
    $clave = (string) Str::uuid();
    $servicio->cobrar($this->actor, $pedido->id, $this->turno->id, $clave, $plan['huella'], $datos, $seleccion, $pagos);
    expect(fn () => $servicio->cobrar($this->actor, $pedido->id, $this->turno->id, (string) Str::uuid(), $plan['huella'], $datos, $seleccion, $pagos))->toThrow(ValidationException::class);
    expect(fn () => $servicio->cobrar($this->actor, $otro->id, $this->turno->id, $clave, $plan['huella'], $datos, $seleccion, $pagos))->toThrow(HttpException::class);
    expect(Cobro::count())->toBe(1)->and($pedido->detalles()->first()->cantidad_cobrada)->toBe('1.0000');
});

test('review checks client and payment snapshots again while later edits preserve receipts and cash summaries', function () {
    $cliente = Cliente::create(['tipo' => 'persona', 'nombre' => 'Cliente original', 'activo' => true]);
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    $datos = datosCobros(['clienteId' => (string) $cliente->id]);
    $seleccion = seleccionCobros($pedido);
    $pagos = ['a' => pagoCobros('efectivo', '6')];
    $servicio = app(GestionCobros::class);
    $plan = $servicio->previsualizar($this->actor, $pedido->id, $this->turno->id, $datos, $seleccion, $pagos);
    $cliente->update(['nombre' => 'Cliente actualizado']);
    expect(fn () => $servicio->cobrar($this->actor, $pedido->id, $this->turno->id, (string) Str::uuid(), $plan['huella'], $datos, $seleccion, $pagos))->toThrow(ValidationException::class);
    $cobro = realizarCobro($this->actor, $pedido, $this->turno, $seleccion, $pagos, $datos);
    $cliente->update(['nombre' => 'Otro nombre']);
    MedioPago::where('codigo', 'efectivo')->update(['nombre' => 'Nombre nuevo', 'afecta_efectivo' => false]);
    $this->get(route('pos.cobro', $cobro))->assertOk()->assertSee('Cliente actualizado')->assertSee('Efectivo')->assertDontSee('Nombre nuevo');
    expect(app(ResumenTurno::class)->calcular($this->turno)['medios'][MedioPago::where('codigo', 'efectivo')->first()->id]['nombre'])->toBe('Efectivo')->and($this->turno->efectivoEsperado())->toBe('106.00');
});

test('cashiers can receive only in their authorized boxes and cannot view another receipt', function () {
    $cajero = User::factory()->create();
    $cajero->assignRole('cajero');
    $turnoDos = app(GestionCajas::class)->abrir($this->actor, Caja::skip(1)->firstOrFail()->id, $cajero->id, '0', '', (string) Str::uuid());
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 2]);
    expect(fn () => realizarCobro($cajero, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '12')]))->toThrow(HttpException::class);
    $cobro = realizarCobro($cajero, $pedido, $turnoDos, seleccionCobros($pedido, [$this->gaseosa->id => 1]), ['a' => pagoCobros('efectivo', '6')]);
    $ajeno = realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '6')]);
    $this->actingAs($cajero)->get(route('pos.cobro', $cobro))->assertOk();
    $this->get(route('pos.cobro', $ajeno))->assertForbidden();
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido->fresh()])->assertSee($cobro->codigo)->assertDontSee($ajeno->codigo);
    expect($turnoDos->efectivoEsperado())->toBe('6.00')->and($this->turno->efectivoEsperado())->toBe('100.00');
});

test('order scope inactive users and missing action permissions protect payment endpoints', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    $mozo = User::factory()->create();
    $mozo->assignRole('mozo');
    $this->actingAs($mozo)->get(route('pos.cobrar', $pedido))->assertForbidden();
    expect(fn () => realizarCobro($mozo, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '6')]))->toThrow(AuthorizationException::class);
    $propio = User::factory()->create();
    $propio->givePermissionTo(['pos.ver', 'pedidos.ver_propios', 'cobros.crear', 'cajas.ver_todas']);
    expect(fn () => realizarCobro($propio, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '6')]))->toThrow(HttpException::class);
    $this->actor->update(['activo' => false]);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '6')]))->toThrow(HttpException::class);
});

test('client assignment permissions and inactive clients are checked for each paying group', function () {
    $cliente = Cliente::create(['tipo' => 'persona', 'nombre' => 'Cliente inactivo', 'activo' => false]);
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '6')], ['clienteId' => (string) $cliente->id]))->toThrow(ValidationException::class);
    $cajero = User::factory()->create();
    $cajero->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'cobros.crear', 'cajas.ver_todas']);
    expect(fn () => realizarCobro($cajero, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '6')], ['clienteId' => (string) $cliente->id]))->toThrow(AuthorizationException::class);
    $pedido->update(['cliente_id' => $cliente->id]);
    $cobro = realizarCobro($cajero, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '6')], ['clienteId' => (string) $cliente->id]);
    expect($cobro->cliente_nombre)->toBe('Cliente inactivo');
});

test('stock price changes cannot replace saved order prices when collecting', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $this->chaufa->update(['precio_venta' => '90']);
    $cobro = realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '20')]);
    expect($cobro->total)->toBe('20.00')->and($cobro->detalles()->first()->precio_unitario)->toBe('20.00');
});

test('a manually delivered paid attention frees its own table without cash effects', function () {
    $mesa = Mesa::create(['salon_id' => Salon::firstOrFail()->id, 'codigo' => 'PAGO-1']);
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1], $mesa);
    $cerrar = app(CerrarAtencion::class);
    expect(fn () => $cerrar->cerrar($this->actor, $pedido->id, EstadoCuenta::huella($pedido->fresh())))->toThrow(ValidationException::class);
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '6')]);
    $linea = $pedido->detalles()->first();
    app(GestionEntregas::class)->entregar($this->actor, $pedido->id, $linea->id, '1', GestionPedidos::huella($pedido->fresh()));
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    $version = EstadoCuenta::huella($pedido->fresh());
    $cerrar->cerrar($this->actor, $pedido->id, $version);
    $cerrar->cerrar($this->actor, $pedido->id, $version);
    expect($pedido->fresh()->estado->value)->toBe('cerrado')->and(OcupacionMesa::where('mesa_id', $mesa->id)->exists())->toBeFalse()->and(MovimientoCaja::count())->toBe(0)->and(Cobro::count())->toBe(1);
});

test('unpaid shipping drafts stale closure and missing release permission prevent closing a table', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1], envio: '5');
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '6')]);
    $linea = $pedido->detalles()->first();
    app(GestionEntregas::class)->entregar($this->actor, $pedido->id, $linea->id, '1', GestionPedidos::huella($pedido->fresh()));
    expect(fn () => app(CerrarAtencion::class)->cerrar($this->actor, $pedido->id, EstadoCuenta::huella($pedido->fresh())))->toThrow(ValidationException::class);
    $vieja = EstadoCuenta::huella($pedido->fresh());
    realizarCobro($this->actor, $pedido, $this->turno, [], ['a' => pagoCobros('yape', '5')], ['incluirEnvio' => true]);
    expect(fn () => app(CerrarAtencion::class)->cerrar($this->actor, $pedido->id, $vieja))->toThrow(ValidationException::class);
    $mesa = Mesa::create(['salon_id' => Salon::firstOrFail()->id, 'codigo' => 'PAGO-2']);
    $mesaPedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1], $mesa);
    $actor = User::factory()->create();
    $actor->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'pedidos.editar']);
    expect(fn () => app(CerrarAtencion::class)->cerrar($actor, $mesaPedido->id, EstadoCuenta::huella($mesaPedido->fresh())))->toThrow(AuthorizationException::class);
});

test('livewire requires a new review after input changes and protects locked collection state', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 2]);
    $id = $pedido->detalles()->first()->id;
    $pantalla = Livewire::test(CobroFormulario::class, ['pedido' => $pedido]);
    $clave = array_key_first($pantalla->get('pagos'));
    $pantalla->set('cantidades.'.$id, '1')->set('grupoPagador', 'Grupo revisado')->set('pagos.'.$clave, pagoCobros('efectivo', '6', '10'))->call('review')->assertSet('showChargeReview', true)
        ->set('cantidades.'.$id, '2')->set('grupoPagador', 'Cambio posterior')->set('pagos.'.$clave.'.monto', '12')->call('confirmCharge')->assertHasErrors('seguridad');
    expect(Cobro::count())->toBe(0);
    $pantalla->set('cantidades.'.$id, '1')->set('grupoPagador', 'Grupo revisado')->set('pagos.'.$clave, pagoCobros('efectivo', '6', '10'))->call('review')->assertHasNoErrors()->call('confirmCharge')->assertHasNoErrors();
    $cobro = Cobro::firstOrFail();
    expect($cobro->total)->toBe('6.00')->and($cobro->grupo_pagador)->toBe('Grupo revisado')->and($cobro->pagos()->first()->vuelto)->toBe('4.00');
    expect(fn () => Livewire::test(CobroFormulario::class, ['pedido' => $pedido->fresh()])->set('turnoRevisado', 999))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(CobroDetallePantalla::class, ['cobro' => $cobro])->set('cobroId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('malformed browser arrays and revoked permissions do not bypass validation', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    $id = $pedido->detalles()->first()->id;
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->set('cantidades.'.$id, ['invalida'])->call('review')->assertHasErrors();
    $pantalla = Livewire::test(CobroFormulario::class, ['pedido' => $pedido]);
    $clave = array_key_first($pantalla->get('pagos'));
    $pantalla->set('pagos.'.$clave.'.monto', ['invalido'])->call('review')->assertHasErrors();
    $cajero = User::factory()->create();
    $cajero->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'cobros.crear', 'cajas.ver_todas']);
    $this->actingAs($cajero);
    $pantalla = Livewire::test(CobroFormulario::class, ['pedido' => $pedido]);
    $cajero->revokePermissionTo('cobros.crear');
    $pantalla->call('review')->assertForbidden();
    expect(Cobro::count())->toBe(0);
});

test('changed payment configuration after review requires a new confirmation', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    $datos = datosCobros();
    $seleccion = seleccionCobros($pedido);
    $pagos = ['a' => pagoCobros('efectivo', '6')];
    $servicio = app(GestionCobros::class);
    $plan = $servicio->previsualizar($this->actor, $pedido->id, $this->turno->id, $datos, $seleccion, $pagos);
    MedioPago::where('codigo', 'efectivo')->update(['afecta_efectivo' => false]);
    expect(fn () => $servicio->cobrar($this->actor, $pedido->id, $this->turno->id, (string) Str::uuid(), $plan['huella'], $datos, $seleccion, $pagos))->toThrow(ValidationException::class);
    expect(Cobro::count())->toBe(0)->and(MovimientoCaja::count())->toBe(0);
});

test('cash upper limit is checked before writing but a digital receipt does not increase it', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    $this->turno->update(['fondo_inicial' => '999999999999.99']);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '6')]))->toThrow(ValidationException::class);
    expect(Cobro::count())->toBe(0);
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('yape', '6')]);
    expect($this->turno->efectivoEsperado())->toBe('999999999999.99')->and(MovimientoCaja::count())->toBe(0);
});

test('inconsistent combo billing rolls back parent counters collection details payments and cash', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->combo->id => 1]);
    $pedido->detalles()->whereNotNull('detalle_padre_id')->first()->update(['factor_componente' => null]);
    expect(fn () => realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '25')]))->toThrow(ValidationException::class);
    expect(Cobro::count())->toBe(0)->and(Pago::count())->toBe(0)->and(MovimientoCaja::count())->toBe(0)->and($pedido->detalles()->whereNull('detalle_padre_id')->first()->cantidad_cobrada)->toBe('0.0000');
});

test('a fake paid counter without confirmed receipt cannot release an occupied table', function () {
    $mesa = Mesa::create(['salon_id' => Salon::firstOrFail()->id, 'codigo' => 'PAGO-FAKE']);
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1], $mesa);
    $linea = $pedido->detalles()->first();
    app(GestionEntregas::class)->entregar($this->actor, $pedido->id, $linea->id, '1', GestionPedidos::huella($pedido->fresh()));
    $linea->update(['cantidad_cobrada' => '1']);
    expect(fn () => app(CerrarAtencion::class)->cerrar($this->actor, $pedido->id, EstadoCuenta::huella($pedido->fresh())))->toThrow(ValidationException::class);
    expect(OcupacionMesa::where('mesa_id', $mesa->id)->exists())->toBeTrue()->and($pedido->fresh()->estado->value)->toBe('entregado');
});

test('cashier completes a reviewed partial collection from the second cash box', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 2]);
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido, [$this->gaseosa->id => 1]), ['a' => pagoCobros('yape', '6')]);
    $cajero = User::factory()->create();
    $cajero->assignRole('cajero');
    $turnoDos = app(GestionCajas::class)->abrir($this->actor, Caja::skip(1)->firstOrFail()->id, $cajero->id, '20', '', (string) Str::uuid());
    $this->actingAs($cajero);
    $pantalla = Livewire::test(CobroFormulario::class, ['pedido' => $pedido->fresh()]);
    $clave = array_key_first($pantalla->get('pagos'));
    $pantalla->set('pagos.'.$clave, pagoCobros('efectivo', '6', '10'))->call('review')->assertSet('showChargeReview', true)->call('confirmCharge');
    $cobro = Cobro::latest('id')->firstOrFail();
    expect($cobro->turno_caja_id)->toBe($turnoDos->id)->and($turnoDos->efectivoEsperado())->toBe('26.00');
    $pantalla->assertRedirect(route('pos.cobrar', ['pedido' => $pedido->id, 'cobro' => $cobro->id]));
});

test('confirmation without a reviewed state requests another review without writing money', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->call('confirmCharge')->assertHasErrors('seguridad');
    expect(Cobro::count())->toBe(0)->and(MovimientoCaja::count())->toBe(0);
});

test('paid kitchen-untouched table exposes finalization and releases stock and table exactly once', function () {
    $mesa = Mesa::create(['salon_id' => Salon::firstOrFail()->id, 'codigo' => 'CIERRE-NUEVO']);
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->chaufa->id => 1, $this->gaseosa->id => 1], $mesa);
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '26')]);
    expect($pedido->fresh()->estado->value)->toBe('en_preparacion');
    $pantalla = Livewire::test(PedidoPantalla::class, ['pedido' => $pedido->fresh()]);
    $pantalla->assertSee('Finalizar y liberar mesa')->call('requestClose')->assertDispatched('app:confirm')->call('confirmClose')->assertRedirect(route('pos.index'));
    expect($pedido->fresh()->estado->value)->toBe('cerrado')
        ->and(OcupacionMesa::where('pedido_id', $pedido->id)->exists())->toBeFalse()
        ->and($this->chaufa->articulo->fresh()->stock_actual)->toBe('19.0000')
        ->and($this->gaseosa->articulo->fresh()->stock_actual)->toBe('19.0000')
        ->and($this->chaufa->articulo->fresh()->stock_reservado)->toBe('0.0000')
        ->and(EnvioCocina::where('pedido_id', $pedido->id)->firstOrFail()->terminado_at)->toBeNull()
        ->and(Cobro::count())->toBe(1)->and(MovimientoInventario::where('tipo', 'entrega')->count())->toBe(2);
    app(CerrarAtencion::class)->cerrar($this->actor, $pedido->id, 'reintento');
    expect(MovimientoInventario::where('tipo', 'entrega')->count())->toBe(2)->and(Cobro::count())->toBe(1);
});

test('finalization delivers pending combo components without kitchen clicks and leaves prior deliveries untouched', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->combo->id => 2]);
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '50')]);
    $raiz = $pedido->detalles()->whereNull('detalle_padre_id')->firstOrFail();
    $hoja = $raiz->hijos()->firstOrFail();
    app(GestionEntregas::class)->entregar($this->actor, $pedido->id, $hoja->id, '1', GestionPedidos::huella($pedido->fresh()));
    app(CerrarAtencion::class)->cerrar($this->actor, $pedido->id, EstadoCuenta::huella($pedido->fresh()));
    expect($pedido->fresh()->estado->value)->toBe('cerrado')->and($raiz->fresh()->cantidad_entregada)->toBe('2.0000')
        ->and($this->chaufa->articulo->fresh()->stock_actual)->toBe('18.0000')->and($this->gaseosa->articulo->fresh()->stock_actual)->toBe('18.0000');
});

test('paid finalization rolls back all deliveries if inventory cannot cover a later item', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->chaufa->id => 1, $this->gaseosa->id => 1]);
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '26')]);
    $this->gaseosa->articulo->fresh()->update(['stock_reservado' => '0']);
    expect($this->gaseosa->articulo->fresh()->stock_reservado)->toBe('0.0000');
    expect(fn () => app(CerrarAtencion::class)->cerrar($this->actor, $pedido->id, EstadoCuenta::huella($pedido->fresh())))->toThrow(ValidationException::class);
    expect($pedido->fresh()->estado->value)->not->toBe('cerrado')->and($this->chaufa->articulo->fresh()->stock_actual)->toBe('20.0000')
        ->and(MovimientoInventario::where('tipo', 'entrega')->count())->toBe(0)->and(Cobro::count())->toBe(1);
});

test('finalization rejects missing delivery permission and incomplete payment without side effects', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->gaseosa->id => 2]);
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido, [$this->gaseosa->id => 1]), ['a' => pagoCobros('efectivo', '6')]);
    Livewire::test(PedidoPantalla::class, ['pedido' => $pedido->fresh()])->assertDontSee('Finalizar y liberar mesa');
    expect(fn () => app(CerrarAtencion::class)->cerrar($this->actor, $pedido->id, EstadoCuenta::huella($pedido->fresh())))->toThrow(ValidationException::class);
    realizarCobro($this->actor, $pedido, $this->turno, seleccionCobros($pedido), ['a' => pagoCobros('efectivo', '6')]);
    $sinEntrega = User::factory()->create();
    $sinEntrega->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'pedidos.editar']);
    expect(fn () => app(CerrarAtencion::class)->cerrar($sinEntrega, $pedido->id, EstadoCuenta::huella($pedido->fresh())))->toThrow(AuthorizationException::class);
    expect(MovimientoInventario::where('tipo', 'entrega')->count())->toBe(0)->and($this->gaseosa->articulo->fresh()->stock_actual)->toBe('20.0000');
});

test('preloaded mixed checkout records only used methods and accepts empty and zero unused methods', function () {
    $pedido = cuentaCobros($this->actor, $this->turno, [$this->chaufa->id => 1, $this->gaseosa->id => 1]);
    $cash = MedioPago::where('codigo', 'efectivo')->firstOrFail();
    $yape = MedioPago::where('codigo', 'yape')->firstOrFail();
    $plin = MedioPago::where('codigo', 'plin')->firstOrFail();
    $pantalla = Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->call('mixedPayment');
    expect(count($pantalla->get('pagos')))->toBe(MedioPago::where('activo', true)->count());
    $pantalla->set('pagos.m-'.$cash->id.'.monto', '20')
        ->set('pagos.m-'.$yape->id.'.monto', '6')->set('pagos.m-'.$yape->id.'.referencia', 'OP-MIXTO')
        ->set('pagos.m-'.$plin->id.'.monto', '0')->call('review')->assertHasNoErrors()->assertSet('showChargeReview', true)
        ->call('confirmCharge')->assertHasNoErrors();
    expect(Cobro::firstOrFail()->total)->toBe('26.00')->and(Pago::count())->toBe(2)
        ->and(MovimientoCaja::firstOrFail()->monto)->toBe('20.00')
        ->and($this->chaufa->articulo->fresh()->stock_actual)->toBe('20.0000');
});
