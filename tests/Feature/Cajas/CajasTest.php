<?php

use App\Livewire\Cajas\Cajas;
use App\Livewire\Cajas\TurnoDetalle;
use App\Livewire\Compras\CompraDetalle;
use App\Models\ArqueoCaja;
use App\Models\Articulo;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Models\PagoCompra;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\TipoComprobante;
use App\Models\TurnoCaja;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Cajas\GestionCajas;
use App\Services\Cajas\ResumenTurno;
use App\Services\Compras\GestionCompras;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\ConfiguracionInicialSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([RolesPermisosSeeder::class, CatalogosSeeder::class, ConfiguracionInicialSeeder::class]);
    $this->actor = User::factory()->create();
    $this->actor->assignRole('administrador');
    $this->actingAs($this->actor);
    $this->caja = Caja::orderBy('id')->firstOrFail();
});

function abrirCajaPrueba(User $actor, Caja $caja, string $fondo = '100', ?string $clave = null, ?User $responsable = null): TurnoCaja
{
    return app(GestionCajas::class)->abrir($actor, $caja->id, $responsable?->id ?? $actor->id, $fondo, 'Inicio de prueba', $clave ?? (string) Str::uuid());
}

function versionCaja(TurnoCaja $turno): string
{
    return app(ResumenTurno::class)->calcular($turno->fresh())['huella'];
}

function movimientoCajaPrueba(User $actor, TurnoCaja $turno, string $tipo = 'ingreso', string $monto = '10', ?string $clave = null): MovimientoCaja
{
    return app(GestionCajas::class)->movimiento($actor, $turno->id, $clave ?? (string) Str::uuid(), versionCaja($turno), ['tipo' => $tipo, 'monto' => $monto, 'concepto' => 'Operación de prueba', 'motivo' => 'Motivo registrado para prueba']);
}

function arqueoCajaPrueba(User $actor, TurnoCaja $turno, string $tipo = 'parcial', string $contado = '100', string $observaciones = '', ?string $clave = null): ArqueoCaja
{
    return app(GestionCajas::class)->arquear($actor, $turno->id, $tipo, $contado, $observaciones, $clave ?? (string) Str::uuid(), versionCaja($turno));
}

test('cash module requires consultation and actions never follow from consultation alone', function () {
    auth()->logout();
    $this->get(route('cajas.index'))->assertRedirect(route('login'));
    $lector = User::factory()->create();
    $this->actingAs($lector)->get(route('cajas.index'))->assertForbidden();
    $lector->givePermissionTo('cajas.ver_propias');
    $this->get(route('cajas.index'))->assertOk()->assertDontSee('Revisar apertura');
    expect(fn () => abrirCajaPrueba($lector, $this->caja))->toThrow(AuthorizationException::class);
    $lector->givePermissionTo('cajas.abrir');
    Livewire::test(Cajas::class)->call('openForm', $this->caja->id)->assertSet('showOpen', true);
});

test('opening records one initial balance without an income and is idempotent', function () {
    $clave = (string) Str::uuid();
    $turno = abrirCajaPrueba($this->actor, $this->caja, '125.50', $clave);
    expect(abrirCajaPrueba($this->actor, $this->caja, '125.50', $clave)->id)->toBe($turno->id)
        ->and($turno->fondo_inicial)->toBe('125.50')->and($turno->efectivoEsperado())->toBe('125.50')
        ->and($turno->abierta_por)->toBe($this->actor->id)->and($turno->caja_nombre)->toBe($this->caja->nombre)
        ->and(MovimientoCaja::count())->toBe(0)->and(TurnoCaja::count())->toBe(1);
    $this->get(route('cajas.turno', $turno))->assertOk()->assertSee('S/ 125.50');
});

test('two separate boxes can open with separate responsible users but one box cannot have two shifts', function () {
    $otro = User::factory()->create();
    $otro->assignRole('cajero');
    $uno = abrirCajaPrueba($this->actor, $this->caja);
    $otraCaja = Caja::whereKeyNot($this->caja->id)->firstOrFail();
    $dos = abrirCajaPrueba($this->actor, $otraCaja, '50', responsable: $otro);
    expect($dos->usuario_id)->toBe($otro->id)->and($dos->abierta_por)->toBe($this->actor->id)->and($uno->efectivoEsperado())->toBe('100.00')->and($dos->efectivoEsperado())->toBe('50.00');
    expect(fn () => abrirCajaPrueba($this->actor, $this->caja))->toThrow(ValidationException::class);
});

test('inactive box or inactive responsible cannot open and a cashier cannot assign another responsible', function () {
    $this->caja->update(['activo' => false]);
    expect(fn () => abrirCajaPrueba($this->actor, $this->caja))->toThrow(ValidationException::class);
    $this->caja->update(['activo' => true]);
    $otro = User::factory()->create();
    $otro->assignRole('cajero');
    expect(fn () => abrirCajaPrueba($otro, $this->caja, responsable: $this->actor))->toThrow(HttpException::class);
    $otro->update(['activo' => false]);
    expect(fn () => abrirCajaPrueba($this->actor, $this->caja, responsable: $otro))->toThrow(HttpException::class);
});

test('own consultation never reveals another cashiers financial balances or shift through route or livewire', function () {
    $otro = User::factory()->create(['name' => 'Responsable reservado']);
    $otro->assignRole('cajero');
    $ajeno = abrirCajaPrueba($this->actor, $this->caja, '9876.54', responsable: $otro);
    $mio = User::factory()->create();
    $mio->assignRole('cajero');
    $this->actingAs($mio);
    $this->get(route('cajas.index'))->assertOk()->assertDontSee('9876.54')->assertDontSee('Responsable reservado')->assertSee('En uso por otro responsable.');
    $this->get(route('cajas.turno', $ajeno))->assertForbidden();
    Livewire::test(TurnoDetalle::class, ['turno' => $ajeno])->assertForbidden();
    expect(fn () => movimientoCajaPrueba($mio, $ajeno))->toThrow(HttpException::class);
    $mio->givePermissionTo('cajas.ver_todas');
    $this->get(route('cajas.turno', $ajeno))->assertOk()->assertSee('9876.54');
});

test('income expense and withdrawal use exact amounts with linked actor and no double counting', function () {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    movimientoCajaPrueba($this->actor, $turno, 'ingreso', '20.25');
    movimientoCajaPrueba($this->actor, $turno, 'egreso', '10.10');
    $clave = (string) Str::uuid();
    $retiro = movimientoCajaPrueba($this->actor, $turno, 'retiro', '30', $clave);
    expect(movimientoCajaPrueba($this->actor, $turno, 'retiro', '30', $clave)->id)->toBe($retiro->id);
    $resumen = app(ResumenTurno::class)->calcular($turno);
    expect($resumen['ingresos'])->toBe('20.25')->and($resumen['egresos'])->toBe('10.10')->and($resumen['retiros'])->toBe('30.00')
        ->and($resumen['esperado'])->toBe('80.15')->and($turno->efectivoEsperado())->toBe('80.15')->and(MovimientoCaja::count())->toBe(3)->and($retiro->usuario_id)->toBe($this->actor->id);
});

test('cash values reject malformed fractional negative zero expenses and unavailable cash', function (string $valor) {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    expect(fn () => movimientoCajaPrueba($this->actor, $turno, 'egreso', $valor))->toThrow(ValidationException::class);
    expect(MovimientoCaja::count())->toBe(0)->and($turno->efectivoEsperado())->toBe('100.00');
})->with(['-1', '0', '1e2', '0.001', '101', '9999999999999']);

test('every manual action needs its exact permission concept and reason', function () {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    $cajero = User::factory()->create();
    $cajero->assignRole('cajero');
    $turno->update(['usuario_id' => $cajero->id]);
    expect(fn () => movimientoCajaPrueba($cajero, $turno, 'retiro'))->toThrow(AuthorizationException::class);
    expect(fn () => app(GestionCajas::class)->movimiento($this->actor, $turno->id, (string) Str::uuid(), versionCaja($turno), ['tipo' => 'ingreso', 'monto' => '5', 'concepto' => '', 'motivo' => '']))->toThrow(ValidationException::class);
    expect(MovimientoCaja::count())->toBe(0);
});

test('partial count preserves money and shift and requires an explanation of differences', function () {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    expect(fn () => arqueoCajaPrueba($this->actor, $turno, contado: '98'))->toThrow(ValidationException::class);
    $clave = (string) Str::uuid();
    $arqueo = arqueoCajaPrueba($this->actor, $turno, contado: '98', observaciones: 'Faltante pendiente de revisar', clave: $clave);
    expect(arqueoCajaPrueba($this->actor, $turno, contado: '98', observaciones: 'Faltante pendiente de revisar', clave: $clave)->id)->toBe($arqueo->id);
    expect($arqueo->diferencia)->toBe('-2.00')->and($arqueo->efectivo_esperado)->toBe('100.00')->and($arqueo->resumen['esperado'])->toBe('100.00')
        ->and($turno->fresh()->estado->value)->toBe('abierta')->and($turno->efectivoEsperado())->toBe('100.00')->and(MovimientoCaja::count())->toBe(0)->and(ArqueoCaja::count())->toBe(1);
    movimientoCajaPrueba($this->actor, $turno);
    expect($arqueo->fresh()->efectivo_esperado)->toBe('100.00');
});

test('closing creates one immutable final count and later opening starts with an explicit new fund', function () {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    $clave = (string) Str::uuid();
    $cierre = arqueoCajaPrueba($this->actor, $turno, 'cierre', '105', 'Sobrante de efectivo detectado', $clave);
    expect(arqueoCajaPrueba($this->actor, $turno, 'cierre', '105', 'Sobrante de efectivo detectado', $clave)->id)->toBe($cierre->id);
    expect($turno->fresh()->estado->value)->toBe('cerrada')->and($turno->fresh()->cerrada_por)->toBe($this->actor->id)
        ->and($cierre->diferencia)->toBe('5.00')->and(ArqueoCaja::count())->toBe(1);
    expect(fn () => movimientoCajaPrueba($this->actor, $turno))->toThrow(ValidationException::class);
    expect(fn () => arqueoCajaPrueba($this->actor, $turno, 'cierre'))->toThrow(ValidationException::class);
    expect(fn () => arqueoCajaPrueba($this->actor, $turno))->toThrow(ValidationException::class);
    $nuevo = abrirCajaPrueba($this->actor, $this->caja, '0');
    expect($nuevo->id)->not->toBe($turno->id)->and($nuevo->efectivoEsperado())->toBe('0.00')->and($turno->fresh()->fondo_inicial)->toBe('100.00');
});

test('closing may proceed with pending orders without releasing or modifying them', function () {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    $pedido = Pedido::create(['codigo' => 'CAJA-PEND', 'modalidad' => 'rapida', 'creado_por' => $this->actor->id, 'estado' => 'abierto', 'abierto_at' => now()]);
    arqueoCajaPrueba($this->actor, $turno, 'cierre');
    expect($pedido->fresh()->estado->value)->toBe('abierto')->and($turno->fresh()->estado->value)->toBe('cerrada');
});

test('stale ledger versions cannot close or move money after another operation', function () {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    $version = versionCaja($turno);
    movimientoCajaPrueba($this->actor, $turno);
    expect(fn () => app(GestionCajas::class)->arquear($this->actor, $turno->id, 'cierre', '100', '', (string) Str::uuid(), $version))->toThrow(ValidationException::class);
    expect(fn () => app(GestionCajas::class)->movimiento($this->actor, $turno->id, (string) Str::uuid(), $version, ['tipo' => 'egreso', 'monto' => '5', 'concepto' => 'Gasto menor', 'motivo' => 'Compra registrada']))->toThrow(ValidationException::class);
    expect($turno->fresh()->estado->value)->toBe('abierta')->and(MovimientoCaja::count())->toBe(1);
});

test('mixed sales payments appear separately and never inflate physical cash', function () {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    $pedido = Pedido::create(['codigo' => 'CAJA-VENTA', 'modalidad' => 'rapida', 'creado_por' => $this->actor->id, 'abierto_at' => now()]);
    $cobro = Cobro::create(['codigo' => 'CAJA-COBRO', 'pedido_id' => $pedido->id, 'turno_caja_id' => $turno->id, 'usuario_id' => $this->actor->id, 'estado' => 'confirmado', 'moneda' => 'PEN', 'total' => '50', 'clave_idempotencia' => (string) Str::uuid()]);
    foreach (['efectivo' => '20', 'yape' => '30'] as $codigo => $monto) {
        Pago::create(['cobro_id' => $cobro->id, 'medio_pago_id' => MedioPago::where('codigo', $codigo)->value('id'), 'monto' => $monto, 'monto_entregado' => $codigo === 'efectivo' ? '50' : $monto,
            'vuelto' => $codigo === 'efectivo' ? '30' : '0', 'recibido_at' => now(), 'clave_idempotencia' => (string) Str::uuid()]);
    }
    // Movimiento que el futuro servicio de cobro registrará por el efectivo aplicado, no por lo entregado.
    MovimientoCaja::create(['turno_caja_id' => $turno->id, 'usuario_id' => $this->actor->id, 'tipo' => 'ingreso', 'concepto' => 'Cobro de venta', 'monto' => '20', 'origen_type' => Cobro::class, 'origen_id' => $cobro->id, 'clave_idempotencia' => (string) Str::uuid(), 'registrado_at' => now()]);
    $resumen = app(ResumenTurno::class)->calcular($turno);
    expect($resumen['esperado'])->toBe('120.00')->and($resumen['cobrado'])->toBe('50.00')->and(array_column($resumen['medios'], 'monto'))->toBe(['20.00', '30.00']);
    $cierre = arqueoCajaPrueba($this->actor, $turno, 'cierre', '120');
    MedioPago::where('codigo', 'yape')->update(['nombre' => 'Nombre cambiado']);
    $this->get(route('cajas.turno', $turno))->assertOk()->assertSee('Yape')->assertDontSee('Nombre cambiado');
    expect($cierre->fresh()->resumen['cobrado'])->toBe('50.00');
});

test('supplier cash payment uses an open UI-created shift and closure blocks later supplier cash', function () {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    $proveedor = Proveedor::create(['nombre' => 'Proveedor de caja']);
    $insumo = Articulo::create(['codigo' => 'CAJA-INS', 'nombre' => 'Insumo caja', 'tipo' => 'insumo', 'unidad_medida_id' => UnidadMedida::where('codigo', 'und')->value('id')]);
    $servicio = app(GestionCompras::class);
    $compra = $servicio->guardar($this->actor, null, (string) Str::uuid(), ['proveedorId' => (string) $proveedor->id, 'tipoId' => (string) TipoComprobante::where('codigo', '00')->value('id'),
        'serie' => '', 'numero' => '', 'fecha' => now()->format('Y-m-d'), 'vencimiento' => now()->format('Y-m-d'), 'condicion' => 'credito', 'observaciones' => ''],
        ['a' => ['articuloId' => (string) $insumo->id, 'presentacionId' => '', 'cantidad' => '1', 'costo' => '20', 'descuento' => '0', 'impuestos' => '0']]);
    $datos = ['origen' => 'caja', 'medioId' => (string) MedioPago::where('codigo', 'efectivo')->value('id'), 'turnoId' => (string) $turno->id, 'monto' => '10', 'referencia' => '', 'observaciones' => ''];
    $servicio->confirmar($this->actor, $compra->id, GestionCompras::huella($compra->fresh()), (string) Str::uuid(), $datos);
    Livewire::test(CompraDetalle::class, ['compra' => $compra])->call('requestPayment')->set('pago', $datos)->call('reviewPayment')->call('execute')->assertHasNoErrors();
    expect($turno->efectivoEsperado())->toBe('90.00')->and($compra->saldoPendiente())->toBe('10.00')->and(PagoCompra::count())->toBe(1);
    Livewire::test(TurnoDetalle::class, ['turno' => $turno])->set('origenFilter', 'proveedor')->assertSee('Pago a proveedor')->assertSee('Pago de compra');
    arqueoCajaPrueba($this->actor, $turno, 'cierre', '90');
    expect(fn () => $servicio->registrarPago($this->actor, $compra->id, (string) Str::uuid(), $datos))->toThrow(ValidationException::class);
    expect($compra->saldoPendiente())->toBe('10.00');
});

test('livewire confirmations keep values locked and revoked permissions are enforced at final action', function () {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    $componente = Livewire::test(TurnoDetalle::class, ['turno' => $turno])->call('openMovement', 'ingreso')->set('monto', '5')->set('concepto', 'Aporte adicional')->set('motivo', 'Aporte de efectivo')->call('reviewMovement')->assertDispatched('app:confirm');
    expect(MovimientoCaja::count())->toBe(0);
    $componente->set('monto', '500')->call('execute')->assertHasNoErrors();
    expect($turno->efectivoEsperado())->toBe('105.00');
    expect(fn () => Livewire::test(TurnoDetalle::class, ['turno' => $turno])->set('turnoId', 99))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(TurnoDetalle::class, ['turno' => $turno])->set('movimientoConfirmado', ['tipo' => 'retiro']))->toThrow(CannotUpdateLockedPropertyException::class);
    $cerrar = Livewire::test(TurnoDetalle::class, ['turno' => $turno])->call('openCount', 'cierre')->set('contado', '105')->call('reviewCount')->assertDispatched('app:confirm');
    $this->actor->roles->first()->revokePermissionTo('cajas.cerrar');
    $this->actor->unsetRelations();
    $cerrar->call('execute')->assertForbidden();
    expect($turno->fresh()->estado->value)->toBe('abierta')->and(ArqueoCaja::count())->toBe(0);
});

test('opening form reviews values before writing and history filters stay scoped', function () {
    $componente = Livewire::test(Cajas::class)->call('openForm', $this->caja->id)->set('fondo', '60')->call('reviewOpen')->assertDispatched('app:confirm');
    expect(TurnoCaja::count())->toBe(0);
    $componente->set('fondo', '600')->call('confirmOpen')->assertHasNoErrors();
    $turno = TurnoCaja::firstOrFail();
    expect($turno->fondo_inicial)->toBe('60.00');
    arqueoCajaPrueba($this->actor, $turno, 'cierre', '60');
    Livewire::test(Cajas::class)->set('stateFilter', 'abierta')->assertDontSee('turno '.$turno->id)->set('stateFilter', 'cerrada')->assertSee('turno '.$turno->id)
        ->set('cajaFilter', '99999')->assertDontSee('turno '.$turno->id)->call('clearFilters')->set('from', 'fecha-mal')->assertSee('turno '.$turno->id);
});

test('closed history preserves box and responsible names and actions do not reappear', function () {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    arqueoCajaPrueba($this->actor, $turno, 'cierre');
    $nombre = $this->caja->nombre;
    $responsable = $this->actor->name;
    $this->caja->update(['nombre' => 'Caja renombrada']);
    $this->actor->update(['name' => 'Usuario renombrado']);
    $this->get(route('cajas.turno', $turno))->assertOk()->assertSee($nombre)->assertSee($responsable)->assertSee('Turno cerrado')->assertDontSee('Revisar movimiento')->assertDontSee('Cerrar caja');
});

test('opening a new cash dialog clears a previously reviewed operation', function () {
    $turno = abrirCajaPrueba($this->actor, $this->caja);
    $componente = Livewire::test(TurnoDetalle::class, ['turno' => $turno])->call('openMovement', 'ingreso')->set('monto', '5')->set('concepto', 'Aporte adicional')->set('motivo', 'Aporte revisado')->call('reviewMovement');
    $componente->call('openMovement', 'egreso')->assertSet('movimientoConfirmado', [])->call('execute')->assertForbidden();
    expect(MovimientoCaja::count())->toBe(0);
    Livewire::test(TurnoDetalle::class, ['turno' => $turno])->call('openCount', 'parcial')->set('contado', '100')->call('reviewCount')->call('openCount', 'cierre')->assertSet('arqueoConfirmado', [])->call('execute')->assertForbidden();
    expect(ArqueoCaja::count())->toBe(0);
});
