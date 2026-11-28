<?php

use App\Livewire\Compras\CompraDetalle as DetalleLivewire;
use App\Livewire\Compras\CompraFormulario;
use App\Livewire\Compras\Compras;
use App\Livewire\Compras\Proveedores;
use App\Livewire\Compras\ProveedorFormulario;
use App\Models\Articulo;
use App\Models\Caja;
use App\Models\Compra;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\PagoCompra;
use App\Models\PresentacionArticulo;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\TipoComprobante;
use App\Models\TipoDocumentoIdentidad;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Compras\GestionCompras;
use App\Services\Inventario\GestionPreparaciones;
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
    $this->proveedor = Proveedor::create(['nombre' => 'Distribuidora de prueba', 'activo' => true]);
    $this->arroz = Articulo::where('codigo', 'EJ-ARROZ')->firstOrFail();
    $this->presentacion = $this->arroz->presentaciones()->firstOrFail();
});

function datosCompra(Proveedor $proveedor, array $cambios = []): array
{
    return array_replace(['proveedorId' => (string) $proveedor->id, 'tipoId' => (string) TipoComprobante::where('codigo', '00')->value('id'),
        'serie' => '', 'numero' => '', 'fecha' => now()->format('Y-m-d'), 'vencimiento' => now()->addDays(10)->format('Y-m-d'), 'condicion' => 'credito', 'observaciones' => 'Prueba'], $cambios);
}

function filaCompra(Articulo $articulo, array $cambios = []): array
{
    return array_replace(['articuloId' => (string) $articulo->id, 'presentacionId' => '', 'cantidad' => '10', 'costo' => '2', 'descuento' => '0', 'impuestos' => '0'], $cambios);
}

function crearCompra(User $actor, Proveedor $proveedor, Articulo $articulo, array $datos = [], array $fila = [], ?string $clave = null): Compra
{
    return app(GestionCompras::class)->guardar($actor, null, $clave ?? (string) Str::uuid(), datosCompra($proveedor, $datos), ['uno' => filaCompra($articulo, $fila)])->refresh();
}

function datosPago(array $cambios = []): array
{
    return array_replace(['origen' => 'empresa', 'medioId' => (string) MedioPago::where('codigo', 'yape')->value('id'), 'turnoId' => '', 'referencia' => 'OP-001', 'observaciones' => '', 'monto' => '10'], $cambios);
}

function recibirCompra(User $actor, Compra $compra, array $pago = []): Compra
{
    return app(GestionCompras::class)->confirmar($actor, $compra->id, GestionCompras::huella($compra->fresh()), (string) Str::uuid(), datosPago($pago));
}

function turnoCompra(User $actor, string $fondo = '100'): TurnoCaja
{
    return TurnoCaja::create(['caja_id' => Caja::firstOrFail()->id, 'usuario_id' => $actor->id, 'fondo_inicial' => $fondo, 'abierta_at' => now(), 'estado' => 'abierta']);
}

test('purchase and supplier routes require their specific permissions and readers cannot mutate', function () {
    auth()->logout();
    $this->get(route('compras.index'))->assertRedirect(route('login'));
    $lector = User::factory()->create();
    $lector->givePermissionTo('compras.ver');
    $this->actingAs($lector);
    $this->get(route('compras.index'))->assertOk()->assertDontSee('Nueva compra');
    $this->get(route('compras.crear'))->assertForbidden();
    $this->get(route('proveedores.index'))->assertForbidden();
    expect(fn () => crearCompra($lector, $this->proveedor, $this->arroz))->toThrow(AuthorizationException::class);
});

test('supplier form validates identity uniqueness lengths and optional contact data', function () {
    $ruc = TipoDocumentoIdentidad::where('codigo', '6')->value('id');
    Livewire::test(ProveedorFormulario::class)->set('nombre', '  Proveedor   Norte ')->set('tipoId', (string) $ruc)->set('numero', '20123456789')->set('telefono', '999000111')->call('save')->assertHasNoErrors();
    $proveedor = Proveedor::where('numero_documento', '20123456789')->firstOrFail();
    expect($proveedor->nombre)->toBe('Proveedor Norte');
    Livewire::test(ProveedorFormulario::class)->set('nombre', 'Otro')->set('tipoId', (string) $ruc)->set('numero', '20123456789')->call('save')->assertHasErrors('numero');
    Livewire::test(ProveedorFormulario::class)->set('nombre', 'Otro')->set('tipoId', (string) $ruc)->set('numero', '12345678')->call('save')->assertHasErrors('numero');
    Livewire::test(ProveedorFormulario::class)->set('nombre', 'Sin documento')->call('save')->assertHasNoErrors();
    Livewire::test(ProveedorFormulario::class)->set('nombre', 'Otro')->set('numero', '12345678')->call('save')->assertHasErrors('tipoId');
    $this->get(route('proveedores.editar', $proveedor))->assertOk();
});

test('supplier updates protect versions and deactivation preserves debts and allows settling them', function () {
    $compra = recibirCompra($this->actor, crearCompra($this->actor, $this->proveedor, $this->arroz));
    $form = Livewire::test(ProveedorFormulario::class, ['proveedor' => $this->proveedor]);
    $this->proveedor->update(['telefono' => '999']);
    $form->set('nombre', 'Cambio viejo')->call('save')->assertHasErrors('seguridad');
    Livewire::test(Proveedores::class)->call('requestStateChange', $this->proveedor->id)->assertDispatched('app:confirm')->call('confirmState')->assertHasNoErrors();
    expect($this->proveedor->fresh()->activo)->toBeFalse();
    app(GestionCompras::class)->registrarPago($this->actor, $compra->id, (string) Str::uuid(), datosPago(['monto' => '20']));
    expect($compra->saldoPendiente())->toBe('0.00');
    expect(fn () => crearCompra($this->actor, $this->proveedor, $this->arroz))->toThrow(ValidationException::class);
});

test('draft scales presentations snapshots quantities and totals without any inventory movement and repeated save is idempotent', function () {
    $clave = (string) Str::uuid();
    $compra = crearCompra($this->actor, $this->proveedor, $this->arroz, [], ['presentacionId' => (string) $this->presentacion->id, 'cantidad' => '2', 'costo' => '150', 'descuento' => '10', 'impuestos' => '20'], $clave);
    $detalle = $compra->detalles()->firstOrFail();
    expect($detalle->cantidad_base)->toBe('100000.0000')->and($detalle->factor_conversion)->toBe('50000.0000')
        ->and($detalle->unidad_codigo)->toBe('g')->and($detalle->presentacion_nombre)->toBe('Saco de 50 kg')
        ->and($compra->subtotal)->toBe('280.00')->and($compra->descuento)->toBe('10.00')->and($compra->impuestos)->toBe('20.00')->and($compra->total)->toBe('290.00')
        ->and($this->arroz->fresh()->stock_actual)->toBe('0.0000')->and(MovimientoInventario::count())->toBe(0)->and(PagoCompra::count())->toBe(0);
    expect(crearCompra($this->actor, $this->proveedor, $this->arroz, [], [], $clave)->id)->toBe($compra->id)->and(Compra::count())->toBe(1);
    $this->get(route('compras.ver', $compra))->assertOk()->assertSee('100000');
});

test('confirmation records one receipt preserves reserved stock and weights acquisition cost', function () {
    $this->arroz->update(['stock_actual' => '10', 'stock_reservado' => '2', 'costo_promedio' => '1']);
    $compra = crearCompra($this->actor, $this->proveedor, $this->arroz);
    recibirCompra($this->actor, $compra);
    recibirCompra($this->actor, $compra); // Confirmar de nuevo no repite la recepción.
    expect($this->arroz->fresh()->stock_actual)->toBe('20.0000')->and($this->arroz->fresh()->stock_reservado)->toBe('2.0000')
        ->and($this->arroz->fresh()->costo_promedio)->toBe('1.500000')->and(MovimientoInventario::count())->toBe(1)
        ->and(MovimientoInventario::first()->costo_unitario)->toBe('2.000000')->and($compra->fresh()->confirmada_por)->toBe($this->actor->id)->and($compra->saldoPendiente())->toBe('20.00');
});

test('received reventa becomes available and purchased ingredients feed preparation without fake balances', function () {
    foreach (['EJ-ARROZ' => '180', 'EJ-POLLO' => '120', 'EJ-HUEVO' => '1'] as $codigo => $cantidad) {
        $articulo = Articulo::where('codigo', $codigo)->firstOrFail();
        recibirCompra($this->actor, crearCompra($this->actor, $this->proveedor, $articulo, [], ['cantidad' => $cantidad, 'costo' => '1']));
    }
    $chaufa = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    $servicio = app(GestionPreparaciones::class);
    $calculo = $servicio->previsualizar($this->actor, $chaufa->id, '1');
    $prep = $servicio->guardar($this->actor, null, (string) Str::uuid(), ['productoId' => (string) $chaufa->id, 'cantidad' => '1', 'fecha' => now()->format('Y-m-d'), 'observaciones' => '', 'motivoAjuste' => ''], [], $calculo['calculoHash']);
    $servicio->confirmar($this->actor, $prep->id, GestionPreparaciones::huellaBorrador($prep));
    expect($chaufa->articulo->fresh()->stock_actual)->toBe('1.0000')->and($this->arroz->fresh()->stock_actual)->toBe('0.0000');
    $gaseosa = Articulo::where('codigo', 'EJ-GASEOSA-STOCK')->firstOrFail();
    recibirCompra($this->actor, crearCompra($this->actor, $this->proveedor, $gaseosa, [], ['presentacionId' => (string) $gaseosa->presentaciones->first()->id, 'cantidad' => '2', 'costo' => '24']));
    expect($gaseosa->fresh()->stock_actual)->toBe('24.0000')->and($gaseosa->fresh()->costo_promedio)->toBe('2.000000');
});

test('purchase form cannot use another articles presentation or prepared output or duplicate ingredients', function () {
    $otro = Articulo::where('codigo', 'EJ-GASEOSA-STOCK')->firstOrFail();
    expect(fn () => crearCompra($this->actor, $this->proveedor, $otro, [], ['presentacionId' => (string) $this->presentacion->id]))->toThrow(ValidationException::class);
    $preparado = Articulo::where('tipo', 'preparado')->firstOrFail();
    expect(fn () => crearCompra($this->actor, $this->proveedor, $preparado))->toThrow(ValidationException::class);
    expect(fn () => app(GestionCompras::class)->guardar($this->actor, null, (string) Str::uuid(), datosCompra($this->proveedor), ['a' => filaCompra($this->arroz), 'b' => filaCompra($this->arroz)]))->toThrow(ValidationException::class);
    expect(Compra::count())->toBe(0);
});

test('decimal conversion and money validation reject underflow overflow malformed values and invalid discounts', function (array $fila) {
    expect(fn () => crearCompra($this->actor, $this->proveedor, $this->arroz, [], $fila))->toThrow(ValidationException::class);
    expect(Compra::count())->toBe(0)->and(MovimientoInventario::count())->toBe(0);
})->with([
    [['cantidad' => '-1']], [['cantidad' => '1e5']], [['cantidad' => '0']], [['cantidad' => '0.00001']],
    [['cantidad' => '999999999999', 'costo' => '999999999999']], [['costo' => '1.1234567']],
    [['descuento' => '20']], [['impuestos' => '20.01']],
]);

test('quantities below inventory precision cannot generate free stock and line prices round to cents', function () {
    $presentacion = PresentacionArticulo::create(['articulo_id' => $this->arroz->id, 'nombre' => 'Fracción', 'factor_conversion' => '0.0001']);
    expect(fn () => crearCompra($this->actor, $this->proveedor, $this->arroz, [], ['presentacionId' => (string) $presentacion->id, 'cantidad' => '0.5']))->toThrow(ValidationException::class);
    $compra = crearCompra($this->actor, $this->proveedor, $this->arroz, [], ['cantidad' => '3', 'costo' => '0.335']);
    expect($compra->total)->toBe('1.01');
});

test('invalid future dates maturity document duplicates and missing invoice series are rejected', function () {
    expect(fn () => crearCompra($this->actor, $this->proveedor, $this->arroz, ['fecha' => now()->addDay()->format('Y-m-d')]))->toThrow(ValidationException::class);
    expect(fn () => crearCompra($this->actor, $this->proveedor, $this->arroz, ['vencimiento' => now()->subDay()->format('Y-m-d')]))->toThrow(ValidationException::class);
    $factura = (string) TipoComprobante::where('codigo', '01')->value('id');
    expect(fn () => crearCompra($this->actor, $this->proveedor, $this->arroz, ['tipoId' => $factura]))->toThrow(ValidationException::class);
    crearCompra($this->actor, $this->proveedor, $this->arroz, ['tipoId' => $factura, 'serie' => 'f001', 'numero' => '10']);
    expect(fn () => crearCompra($this->actor, $this->proveedor, $this->arroz, ['tipoId' => $factura, 'serie' => 'F001', 'numero' => '10']))->toThrow(ValidationException::class);
});

test('draft editing and cancellation check version and ownership and confirmed receipts cannot change', function () {
    $compra = crearCompra($this->actor, $this->proveedor, $this->arroz);
    $version = GestionCompras::huella($compra);
    $viejo = Livewire::test(CompraFormulario::class, ['compra' => $compra]);
    app(GestionCompras::class)->guardar($this->actor, $compra->id, $compra->clave_registro, datosCompra($this->proveedor), ['uno' => filaCompra($this->arroz, ['cantidad' => '15'])], $version);
    $viejo->set('observaciones', 'Viejas')->call('save')->assertHasErrors('seguridad');
    expect(fn () => app(GestionCompras::class)->anular($this->actor, $compra->id, $version, 'Duplicado'))->toThrow(ValidationException::class);
    recibirCompra($this->actor, $compra);
    expect(fn () => app(GestionCompras::class)->anular($this->actor, $compra->id, GestionCompras::huella($compra->fresh()), 'Error de datos'))->toThrow(ValidationException::class);
    $this->get(route('compras.editar', $compra))->assertForbidden();
    $borrador = crearCompra($this->actor, $this->proveedor, $this->arroz);
    Livewire::test(DetalleLivewire::class, ['compra' => $borrador])->set('motivo', 'Compra duplicada')->call('requestCancel')->assertDispatched('app:confirm')->call('confirmCancel')->assertHasNoErrors();
    expect($borrador->fresh()->estado->value)->toBe('anulada')->and(MovimientoInventario::count())->toBe(1);
});

test('last invalid receipt line rolls back every stock balance and movement and inactive supplier blocks confirmation', function () {
    $pollo = Articulo::where('codigo', 'EJ-POLLO')->firstOrFail();
    $compra = app(GestionCompras::class)->guardar($this->actor, null, (string) Str::uuid(), datosCompra($this->proveedor), ['a' => filaCompra($this->arroz), 'b' => filaCompra($pollo)]);
    $pollo->update(['activo' => false]);
    expect(fn () => recibirCompra($this->actor, $compra))->toThrow(ValidationException::class);
    expect($this->arroz->fresh()->stock_actual)->toBe('0.0000')->and($compra->fresh()->estado->value)->toBe('borrador')->and(MovimientoInventario::count())->toBe(0);
    $pollo->update(['activo' => true]);
    $this->proveedor->update(['activo' => false]);
    expect(fn () => recibirCompra($this->actor, $compra))->toThrow(ValidationException::class);
});

test('cash purchase requires authorized payment and records inventory and complete payment together', function () {
    $compra = crearCompra($this->actor, $this->proveedor, $this->arroz, ['condicion' => 'contado']);
    recibirCompra($this->actor, $compra);
    expect($compra->saldoPendiente())->toBe('0.00')->and(PagoCompra::first()->monto)->toBe('20.00')->and(MovimientoCaja::count())->toBe(0)->and($this->arroz->fresh()->stock_actual)->toBe('10.0000');
    recibirCompra($this->actor, $compra);
    expect(PagoCompra::count())->toBe(1)->and(MovimientoInventario::count())->toBe(1);
});

test('failed payment on contado rolls back receipt and confirmation', function () {
    $compra = crearCompra($this->actor, $this->proveedor, $this->arroz, ['condicion' => 'contado']);
    expect(fn () => recibirCompra($this->actor, $compra, ['origen' => 'empresa', 'medioId' => (string) MedioPago::where('codigo', 'efectivo')->value('id')]))->toThrow(ValidationException::class);
    expect($compra->fresh()->estado->value)->toBe('borrador')->and($this->arroz->fresh()->stock_actual)->toBe('0.0000')->and(PagoCompra::count())->toBe(0)->and(MovimientoInventario::count())->toBe(0);
});

test('credit accepts partial digital and owner payments once rejects overpayment and preserves physical cash', function () {
    $turno = turnoCompra($this->actor);
    $compra = recibirCompra($this->actor, crearCompra($this->actor, $this->proveedor, $this->arroz));
    $clave = (string) Str::uuid();
    $servicio = app(GestionCompras::class);
    $pago = $servicio->registrarPago($this->actor, $compra->id, $clave, datosPago(['monto' => '5']));
    expect($servicio->registrarPago($this->actor, $compra->id, $clave, datosPago(['monto' => '5']))->id)->toBe($pago->id);
    expect($compra->saldoPendiente())->toBe('15.00')->and($turno->efectivoEsperado())->toBe('100.00');
    expect(fn () => $servicio->registrarPago($this->actor, $compra->id, (string) Str::uuid(), datosPago(['monto' => '15.01'])))->toThrow(ValidationException::class);
    $servicio->registrarPago($this->actor, $compra->id, (string) Str::uuid(), datosPago(['origen' => 'dueno', 'medioId' => (string) MedioPago::where('codigo', 'efectivo')->value('id'), 'monto' => '15']));
    expect($compra->saldoPendiente())->toBe('0.00')->and(PagoCompra::count())->toBe(2)->and(MovimientoCaja::count())->toBe(0)->and(MovimientoInventario::count())->toBe(1);
});

test('physical cash payment requires open own authorized turn and sufficient cash and creates one linked expense', function () {
    $turno = turnoCompra($this->actor, '30');
    $compra = recibirCompra($this->actor, crearCompra($this->actor, $this->proveedor, $this->arroz));
    $datos = datosPago(['origen' => 'caja', 'medioId' => (string) MedioPago::where('codigo', 'efectivo')->value('id'), 'turnoId' => (string) $turno->id, 'monto' => '20']);
    $clave = (string) Str::uuid();
    $servicio = app(GestionCompras::class);
    $servicio->registrarPago($this->actor, $compra->id, $clave, $datos);
    $servicio->registrarPago($this->actor, $compra->id, $clave, $datos);
    expect($turno->efectivoEsperado())->toBe('10.00')->and(MovimientoCaja::count())->toBe(1)->and(PagoCompra::count())->toBe(1);
    $otro = recibirCompra($this->actor, crearCompra($this->actor, $this->proveedor, $this->arroz));
    expect(fn () => $servicio->registrarPago($this->actor, $otro->id, (string) Str::uuid(), $datos))->toThrow(ValidationException::class);
    $turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    expect(fn () => $servicio->registrarPago($this->actor, $otro->id, (string) Str::uuid(), $datos))->toThrow(ValidationException::class);
    expect($otro->saldoPendiente())->toBe('20.00');
});

test('payment cannot attach digital company funds to cash or use a digital medium as physical cash', function () {
    $turno = turnoCompra($this->actor);
    $compra = recibirCompra($this->actor, crearCompra($this->actor, $this->proveedor, $this->arroz));
    foreach ([['turnoId' => (string) $turno->id], ['origen' => 'caja', 'turnoId' => (string) $turno->id]] as $cambios) {
        expect(fn () => app(GestionCompras::class)->registrarPago($this->actor, $compra->id, (string) Str::uuid(), datosPago($cambios)))->toThrow(ValidationException::class);
    }
    expect(PagoCompra::count())->toBe(0)->and(MovimientoCaja::count())->toBe(0);
});

test('payment permissions are checked after dialog and other users drafts remain protected', function () {
    $compra = recibirCompra($this->actor, crearCompra($this->actor, $this->proveedor, $this->arroz));
    $detalle = Livewire::test(DetalleLivewire::class, ['compra' => $compra])->call('requestPayment')->set('pago', datosPago())->call('reviewPayment')->assertDispatched('app:confirm');
    $this->actor->roles->first()->revokePermissionTo('pagos_proveedor.registrar');
    $this->actor->unsetRelations();
    $detalle->call('execute')->assertForbidden();
    expect(PagoCompra::count())->toBe(0);
    $otro = User::factory()->create();
    $otro->givePermissionTo(['compras.ver', 'compras.crear']);
    $borrador = crearCompra($this->actor, $this->proveedor, $this->arroz);
    $this->actingAs($otro)->get(route('compras.editar', $borrador))->assertForbidden();
    expect(fn () => Livewire::test(DetalleLivewire::class, ['compra' => $compra])->set('compraId', $borrador->id))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('history snapshots survive renames and lists filter by provider debt dates state and text', function () {
    $compra = recibirCompra($this->actor, crearCompra($this->actor, $this->proveedor, $this->arroz));
    $this->proveedor->update(['nombre' => 'Nombre nuevo']);
    $this->arroz->update(['nombre' => 'Arroz nuevo']);
    $this->get(route('compras.ver', $compra))->assertOk()->assertSee('Distribuidora de prueba')->assertSee('Arroz (ejemplo)');
    Livewire::test(Compras::class)->set('saldoFilter', 'pendiente')->assertSee($compra->codigo)->set('saldoFilter', 'pagada')->assertDontSee($compra->codigo);
    Livewire::test(Compras::class)->set('proveedorId', '99999')->assertDontSee($compra->codigo)->call('clearFilters')->set('from', 'fecha-invalida')->assertSee($compra->codigo);
    Livewire::test(Compras::class)->set('stateFilter', 'borrador')->assertDontSee($compra->codigo);
    Livewire::test(Proveedores::class)->set('search', 'Nombre nuevo')->assertSee('Nombre nuevo')->set('stateFilter', 'inactivo')->assertDontSee('Nombre nuevo');
});

test('livewire purchase form saves reviewable draft and confirms credit with shared sweetalert', function () {
    Livewire::test(CompraFormulario::class)->set('proveedorId', (string) $this->proveedor->id)->set('condicion', 'credito')->set('vencimiento', now()->addDay()->format('Y-m-d'))
        ->set('filas', ['a' => filaCompra($this->arroz)])->call('save')->assertHasNoErrors()->assertRedirect(route('compras.ver', Compra::firstOrFail()));
    $compra = Compra::firstOrFail();
    $detalle = Livewire::test(DetalleLivewire::class, ['compra' => $compra])->call('requestConfirm')->assertDispatched('app:confirm');
    expect($this->arroz->fresh()->stock_actual)->toBe('0.0000');
    $detalle->call('execute')->assertHasNoErrors()->assertSee('Confirmada');
    expect($this->arroz->fresh()->stock_actual)->toBe('10.0000');
});

test('confirmation permissions and dialog versions are rechecked immediately before receiving', function () {
    $compra = crearCompra($this->actor, $this->proveedor, $this->arroz);
    $detalle = Livewire::test(DetalleLivewire::class, ['compra' => $compra])->call('requestConfirm')->assertDispatched('app:confirm');
    $compra->update(['observaciones' => 'Revisado en otra sesión']);
    $detalle->call('execute')->assertDispatched('app:alert');
    expect($compra->fresh()->estado->value)->toBe('borrador')->and(MovimientoInventario::count())->toBe(0);
    $detalle->call('requestConfirm');
    $this->actor->roles->first()->revokePermissionTo('compras.confirmar');
    $this->actor->unsetRelations();
    $detalle->call('execute')->assertForbidden();
    expect(MovimientoInventario::count())->toBe(0);
});

test('delegated supplier payment cannot spend another cashiers cash or bypass expense permission', function () {
    $turno = turnoCompra($this->actor);
    $compra = recibirCompra($this->actor, crearCompra($this->actor, $this->proveedor, $this->arroz));
    $delegado = User::factory()->create();
    $delegado->givePermissionTo(['pagos_proveedor.registrar', 'cajas.registrar_egreso']);
    $datos = datosPago(['origen' => 'caja', 'medioId' => (string) MedioPago::where('codigo', 'efectivo')->value('id'), 'turnoId' => (string) $turno->id]);
    expect(fn () => app(GestionCompras::class)->registrarPago($delegado, $compra->id, (string) Str::uuid(), $datos))->toThrow(HttpException::class);
    $turno->update(['usuario_id' => $delegado->id]);
    $delegado->revokePermissionTo('cajas.registrar_egreso');
    expect(fn () => app(GestionCompras::class)->registrarPago($delegado, $compra->id, (string) Str::uuid(), $datos))->toThrow(AuthorizationException::class);
    expect(MovimientoCaja::count())->toBe(0)->and(PagoCompra::count())->toBe(0);
});

test('payment confirmation keeps reviewed values locked even when editable form values change', function () {
    $compra = recibirCompra($this->actor, crearCompra($this->actor, $this->proveedor, $this->arroz));
    $detalle = Livewire::test(DetalleLivewire::class, ['compra' => $compra])->call('requestPayment')->set('pago', datosPago(['monto' => '5']))->call('reviewPayment')->assertDispatched('app:confirm');
    $detalle->set('pago.monto', '20')->call('execute')->assertHasNoErrors();
    expect(PagoCompra::first()->monto)->toBe('5.00')->and($compra->saldoPendiente())->toBe('15.00');
    expect(fn () => Livewire::test(DetalleLivewire::class, ['compra' => $compra])->set('pagoConfirmado', datosPago(['monto' => '20'])))->toThrow(CannotUpdateLockedPropertyException::class);
});
