<?php

use App\Livewire\Pos\AccionesProducto;
use App\Livewire\Pos\PedidoPantalla;
use App\Models\AnulacionPedido as RegistroAnulacion;
use App\Models\Caja;
use App\Models\ConfiguracionAnulacion;
use App\Models\EnvioCocinaDetalle;
use App\Models\MedioPago;
use App\Models\Mesa;
use App\Models\MotivoAnulacion;
use App\Models\MovimientoInventario;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Salon;
use App\Models\User;
use App\Services\Cajas\GestionCajas;
use App\Services\Cobros\GestionCobros;
use App\Services\Cocina\GestionCocina;
use App\Services\Pos\AnularPedido;
use App\Services\Pos\ConfirmarPedido;
use App\Services\Pos\GestionAnulaciones;
use App\Services\Pos\GestionEntregas;
use App\Services\Pos\GestionPedidos;
use App\Services\Pos\SeguridadAnulaciones;
use Database\Seeders\EjemploInventarioSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolesPermisosSeeder::class, EjemploInventarioSeeder::class]);
    $this->actor = User::factory()->create();
    $this->actor->assignRole('administrador');
    $this->actingAs($this->actor);
    Cache::flush();
    $this->autorizacion = autorizacionAnulacionPrueba('Solicitud del cliente');
    $this->motivoSeguro = 'Solicitud del cliente';
    $this->turno = app(GestionCajas::class)->abrir($this->actor, Caja::firstOrFail()->id, $this->actor->id, '100', '', (string) Str::uuid());
    $this->mesa = Mesa::create(['salon_id' => Salon::firstOrFail()->id, 'codigo' => 'SEGURA']);
    $this->plato = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    $this->bebida = Producto::where('codigo', 'EJ-GASEOSA')->firstOrFail();
    $this->plato->articulo->update(['stock_actual' => '20']);
    $this->bebida->articulo->update(['stock_actual' => '20']);
});

function pedidoSeguro($test, string $tipo): Pedido
{
    $p = app(GestionPedidos::class)->abrir($test->actor, $test->turno->id, (string) Str::uuid(), ['modalidad' => 'mesa', 'mesaId' => (string) $test->mesa->id, 'mozoId' => (string) $test->actor->id, 'comensales' => '2', 'observaciones' => '', 'destinatario' => '', 'telefono' => '', 'direccion' => '', 'referencia' => '', 'costoEnvio' => '0']);
    app(GestionPedidos::class)->guardar($test->actor, $p->id, $test->turno->id, GestionPedidos::huella($p->fresh()), ['clienteId' => '', 'comensales' => '2', 'observaciones' => ''], [
        'plato' => ['detalleId' => '', 'productoId' => (string) $test->plato->id, 'cantidad' => '2', 'nota' => ''],
        'bebida' => ['detalleId' => '', 'productoId' => (string) $test->bebida->id, 'cantidad' => '2', 'nota' => ''],
    ]);
    if ($tipo !== 'borrador') {
        app(ConfirmarPedido::class)->confirmar($test->actor, $p->id, $test->turno->id, GestionPedidos::huella($p->fresh()), (string) Str::uuid());
    }

    return $p->fresh();
}

function planSeguro($test, string $tipo, Pedido $p): array
{
    if ($tipo === 'borrador') {
        return ['huella' => GestionPedidos::huella($p->fresh())];
    }
    if ($tipo === 'pedido') {
        return app(AnularPedido::class)->previsualizar($test->actor, $p->id, $test->motivoSeguro);
    }
    $id = $p->detalles()->where('producto_id', $test->plato->id)->firstOrFail()->id;

    return app(GestionAnulaciones::class)->previsualizar($test->actor, $p->id, $id, '1', $test->motivoSeguro) + ['id' => $id, 'clave' => (string) Str::uuid()];
}

function ejecutarSeguro($test, string $tipo, Pedido $p, array $plan, ?array $autorizacion = null, ?User $actor = null): void
{
    $autorizacion ??= $test->autorizacion;
    $actor ??= $test->actor;
    match ($tipo) {
        'borrador' => app(GestionPedidos::class)->descartar($actor, $p->id, $plan['huella'], $test->motivoSeguro, ...$autorizacion),
        'pedido' => app(AnularPedido::class)->anular($actor, $p->id, $test->motivoSeguro, $plan['huella'], ...$autorizacion),
        default => app(GestionAnulaciones::class)->anular($actor, $p->id, $plan['id'], '1', $test->motivoSeguro, $plan['clave'], $plan['huella'], ...$autorizacion),
    };
}

test('each protected service rejects calls omitting authorization and accepts the reviewed valid operation', function (string $tipo) {
    $p = pedidoSeguro($this, $tipo);
    $plan = planSeguro($this, $tipo, $p);
    expect(fn () => ejecutarSeguro($this, $tipo, $p, $plan, []))->toThrow(ValidationException::class);
    expect($p->fresh()->estado->value)->not->toBe('anulado');
    ejecutarSeguro($this, $tipo, $p, $plan);
    ejecutarSeguro($this, $tipo, $p, $plan);
    expect(fn () => ejecutarSeguro($this, $tipo, $p, $plan, []))->toThrow(ValidationException::class);
    expect($this->plato->articulo->fresh()->stock_actual)->toBe('20.0000');
    if ($tipo === 'parcial') {
        expect($p->detalles()->findOrFail($plan['id'])->cantidad_anulada)->toBe('1.0000')->and(RegistroAnulacion::count())->toBe(1)->and($this->mesa->ocupacion()->exists())->toBeTrue();
    } else {
        expect($p->fresh()->estado->value)->toBe('anulado')->and($this->mesa->ocupacion()->exists())->toBeFalse()->and($this->plato->articulo->fresh()->stock_reservado)->toBe('0.0000');
    }
})->with(['parcial', 'pedido', 'borrador']);

test('bad keys and missing configuration never change any protected operation', function (string $tipo) {
    $p = pedidoSeguro($this, $tipo);
    $plan = planSeguro($this, $tipo, $p);
    expect(fn () => ejecutarSeguro($this, $tipo, $p, $plan, array_replace($this->autorizacion, ['claveAutorizacion' => '999999'])))->toThrow(ValidationException::class);
    ConfiguracionAnulacion::query()->update(['clave_hash' => null]);
    expect(fn () => ejecutarSeguro($this, $tipo, $p, $plan))->toThrow(ValidationException::class);
    expect(RegistroAnulacion::count())->toBe(0)->and(MovimientoInventario::count())->toBe(0)->and($this->mesa->ocupacion()->exists())->toBeTrue();
})->with(['parcial', 'pedido', 'borrador']);

test('missing nonexistent inactive and renamed reasons are refused by protected services', function (string $tipo, string $caso) {
    $p = pedidoSeguro($this, $tipo);
    $plan = planSeguro($this, $tipo, $p);
    $aut = $this->autorizacion;
    match ($caso) {
        'vacio' => $aut['motivoId'] = '',
        'inexistente' => $aut['motivoId'] = 999999,
        'inactivo' => MotivoAnulacion::findOrFail($aut['motivoId'])->update(['activo' => false]),
        'renombrado' => MotivoAnulacion::findOrFail($aut['motivoId'])->update(['nombre' => 'Motivo cambiado']),
    };
    expect(fn () => ejecutarSeguro($this, $tipo, $p, $plan, $aut))->toThrow(ValidationException::class);
    expect(RegistroAnulacion::count())->toBe(0)->and($this->mesa->ocupacion()->exists())->toBeTrue();
})->with(['parcial', 'pedido', 'borrador'])->with(['vacio', 'inexistente', 'inactivo', 'renombrado']);

test('failed key attempts survive rolled back operations and block even a correct key until timeout', function () {
    $p = pedidoSeguro($this, 'pedido');
    $plan = planSeguro($this, 'pedido', $p);
    for ($i = 0; $i < 5; $i++) {
        expect(fn () => ejecutarSeguro($this, 'pedido', $p, $plan, array_replace($this->autorizacion, ['claveAutorizacion' => '999999'])))->toThrow(ValidationException::class);
    }
    expect(RateLimiter::attempts('anulaciones:intentos:'.$this->actor->id))->toBe(5);
    expect(fn () => ejecutarSeguro($this, 'pedido', $p, $plan))->toThrow(ValidationException::class);
    $this->travel(901)->seconds();
    ejecutarSeguro($this, 'pedido', $p, $plan);
    expect($p->fresh()->estado->value)->toBe('anulado');
});

test('permissions are required independently of knowing the key', function (string $tipo) {
    $p = pedidoSeguro($this, $tipo);
    $plan = planSeguro($this, $tipo, $p);
    $otro = User::factory()->create();
    $otro->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'mesas.liberar']);
    expect(fn () => ejecutarSeguro($this, $tipo, $p, $plan, actor: $otro))->toThrow(AuthorizationException::class);
    expect($this->mesa->ocupacion()->exists())->toBeTrue();
})->with(['parcial', 'pedido', 'borrador']);

test('full cancellation validates one key for multiple lines and releases reservations with exact waste', function () {
    $p = pedidoSeguro($this, 'pedido');
    $envio = EnvioCocinaDetalle::firstOrFail();
    app(GestionCocina::class)->avanzar($this->actor, $envio->id, 'iniciar', '1', GestionCocina::huella($envio));
    $plan = planSeguro($this, 'pedido', $p);
    $this->partialMock(SeguridadAnulaciones::class)->shouldReceive('verificarClave')->once()->passthru();
    ejecutarSeguro($this, 'pedido', $p, $plan);
    expect($p->fresh()->estado->value)->toBe('anulado')->and(RegistroAnulacion::count())->toBe(2)
        ->and($this->plato->articulo->fresh()->stock_actual)->toBe('19.0000')->and($this->plato->articulo->fresh()->stock_reservado)->toBe('0.0000')
        ->and($this->bebida->articulo->fresh()->stock_actual)->toBe('20.0000')->and(MovimientoInventario::where('tipo', 'merma')->count())->toBe(1);
});

test('preparation or saved draft changes after review reject the entire operation without partial effects', function (string $tipo) {
    $p = pedidoSeguro($this, $tipo);
    $plan = planSeguro($this, $tipo, $p);
    if ($tipo === 'borrador') {
        $p->detalles()->firstOrFail()->update(['cantidad' => '3']);
    } else {
        $envio = EnvioCocinaDetalle::firstOrFail();
        app(GestionCocina::class)->avanzar($this->actor, $envio->id, 'iniciar', '1', GestionCocina::huella($envio));
    }
    expect(fn () => ejecutarSeguro($this, $tipo, $p, $plan))->toThrow(ValidationException::class);
    expect(RegistroAnulacion::count())->toBe(0)->and(MovimientoInventario::count())->toBe(0)->and($this->mesa->ocupacion()->exists())->toBeTrue();
})->with(['parcial', 'pedido', 'borrador']);

test('paid and delivered quantities block cancellation even with valid authorization', function (string $situacion) {
    $p = pedidoSeguro($this, 'pedido');
    $plan = planSeguro($this, 'pedido', $p);
    $d = $p->detalles()->where('producto_id', $this->bebida->id)->firstOrFail();
    if ($situacion === 'cobrado') {
        $datos = ['clienteId' => '', 'grupoPagador' => '', 'incluirEnvio' => false];
        $seleccion = ['d' => ['detalleId' => (string) $d->id, 'cantidad' => '2']];
        $pagos = ['p' => ['medioId' => (string) MedioPago::where('codigo', 'efectivo')->value('id'), 'monto' => '12', 'entregado' => '', 'referencia' => '']];
        $cobros = app(GestionCobros::class);
        $previo = $cobros->previsualizar($this->actor, $p->id, $this->turno->id, $datos, $seleccion, $pagos);
        $cobros->cobrar($this->actor, $p->id, $this->turno->id, (string) Str::uuid(), $previo['huella'], $datos, $seleccion, $pagos);
    } else {
        app(GestionEntregas::class)->entregar($this->actor, $p->id, $d->id, '2', GestionPedidos::huella($p->fresh()));
    }
    expect(fn () => ejecutarSeguro($this, 'pedido', $p, $plan))->toThrow(ValidationException::class);
    $s = app(GestionAnulaciones::class);
    expect(fn () => $s->previsualizar($this->actor, $p->id, $d->id, '1', $this->motivoSeguro))->toThrow(ValidationException::class);
    expect($this->mesa->ocupacion()->exists())->toBeTrue();
})->with(['cobrado', 'entregado']);

test('Livewire full and draft cancellation preserve reviewed identities and clear secrets on rejection cancel and success', function (string $tipo) {
    $p = pedidoSeguro($this, $tipo);
    $ui = Livewire::test(PedidoPantalla::class, ['pedido' => $p])->call($tipo === 'pedido' ? 'openCancelOrder' : 'openDiscard')
        ->set('motivoAnulacionId', (string) $this->autorizacion['motivoId'])->call($tipo === 'pedido' ? 'reviewCancelOrder' : 'requestDiscard')->assertHasNoErrors()->assertSet('showConfirmCancel', true);
    $confirmar = $tipo === 'pedido' ? 'confirmCancelOrder' : 'confirmDiscard';
    $ui->call($confirmar, '999999')->assertHasErrors('anulacion');
    $otroMotivo = autorizacionAnulacionPrueba('Otro motivo distinto');
    $ui->set('motivoAnulacionId', (string) $otroMotivo['motivoId'])->call($confirmar, $this->autorizacion['claveAutorizacion'])->assertHasNoErrors()->assertRedirect(route('pos.index'));
    expect($p->fresh()->motivo_anulacion)->toBe($this->motivoSeguro);
    expect(json_encode($ui->snapshot))->not->toContain($this->autorizacion['claveAutorizacion'])->and(array_key_exists('claveAutorizacion', $ui->snapshot['data']))->toBeFalse();
    $ui->call($confirmar, $this->autorizacion['claveAutorizacion'])->assertHasNoErrors();
    expect(MovimientoInventario::count())->toBe(0);
})->with(['pedido', 'borrador']);

test('reviewed cancellation identifiers cannot be edited from Livewire', function (string $campo, mixed $valor) {
    $p = pedidoSeguro($this, 'pedido');
    expect(fn () => Livewire::test(PedidoPantalla::class, ['pedido' => $p])->set($campo, $valor))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([['pedidoId', 999], ['motivoAnulacionRevisadoId', 999], ['motivoTotalRevisado', 'Motivo inyectado'], ['tipoAnulacionRevisada', 'borrador'], ['huellaAnulacionTotal', 'otra'], ['versionDescarte', 'otra']]);

test('partial quantity controls stop at available uncharged and undelivered quantities', function () {
    $p = pedidoSeguro($this, 'parcial');
    $d = $p->detalles()->where('producto_id', $this->plato->id)->firstOrFail();
    $ui = Livewire::test(AccionesProducto::class, ['pedidoId' => $p->id])->call('openCancel', $d->id)->assertSet('cantidadMaxima', 2);
    $ui->call('aumentarCantidad')->call('aumentarCantidad')->assertSet('cantidad', '2')->call('disminuirCantidad')->call('disminuirCantidad')->assertSet('cantidad', '1');
    $ui->set('motivoAnulacionId', (string) $this->autorizacion['motivoId'])->call('review')->call('cancelarConfirmacionAnulacion')->assertSet('showConfirmCancel', false);
    expect($d->fresh()->cantidad_anulada)->toBe('0.0000');
});

test('shared coordinator cannot be called directly without the same authorization and failed exceptions contain no secret', function () {
    $p = pedidoSeguro($this, 'pedido');
    $plan = planSeguro($this, 'pedido', $p);
    expect(fn () => app(GestionAnulaciones::class)->anularPedido($this->actor, $p->id, $this->motivoSeguro, $plan['huella']))->toThrow(ValidationException::class);
    try {
        app(GestionAnulaciones::class)->anular($this->actor, $p->id, $p->detalles()->firstOrFail()->id, '1', $this->motivoSeguro, (string) Str::uuid(), '', $this->autorizacion['motivoId'], '942106');
        $this->fail('La clave incorrecta debe rechazarse.');
    } catch (ValidationException $error) {
        expect(json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR))->not->toContain('942106')->and(json_encode($error->errors()))->not->toContain('942106');
    }
    ejecutarSeguro($this, 'pedido', $p, $plan);
    $auditoria = DB::table('activity_log')->pluck('properties')->implode(' ');
    expect($auditoria)->not->toContain($this->autorizacion['claveAutorizacion'])->not->toContain((string) ConfiguracionAnulacion::firstOrFail()->clave_hash);
});

test('whole cancellation requires waste permission independently of cancellation and key', function () {
    $p = pedidoSeguro($this, 'pedido');
    $envio = EnvioCocinaDetalle::firstOrFail();
    app(GestionCocina::class)->avanzar($this->actor, $envio->id, 'iniciar', '1', GestionCocina::huella($envio));
    $otro = User::factory()->create();
    $otro->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'pedidos.anular_enviados', 'mesas.liberar']);
    expect(fn () => app(AnularPedido::class)->anular($otro, $p->id, $this->motivoSeguro, 'huella', ...$this->autorizacion))->toThrow(AuthorizationException::class);
    expect(RegistroAnulacion::count())->toBe(0)->and(MovimientoInventario::count())->toBe(0)->and($this->mesa->ocupacion()->exists())->toBeTrue();
});

test('a late failure rolls back every cancellation reserve waste and table release in the batch', function () {
    $p = pedidoSeguro($this, 'pedido');
    $plan = planSeguro($this, 'pedido', $p);
    $ultimo = $p->detalles()->findOrFail($plan['detalles'][1]['id']);
    $articulo = $ultimo->reservas()->firstOrFail()->articulo;
    $evento = 'eloquent.created: '.RegistroAnulacion::class;
    // Simula un fallo de integridad al procesar la segunda línea.
    Event::listen($evento, function () use ($articulo): void {
        $articulo->update(['stock_reservado' => '0']);
    });
    try {
        expect(fn () => ejecutarSeguro($this, 'pedido', $p, $plan))->toThrow(ValidationException::class);
    } finally {
        Event::forget($evento);
    }
    expect(RegistroAnulacion::count())->toBe(0)->and(MovimientoInventario::count())->toBe(0)
        ->and($this->plato->articulo->fresh()->stock_reservado)->toBe('2.0000')
        ->and($this->bebida->articulo->fresh()->stock_reservado)->toBe('2.0000')->and($this->mesa->ocupacion()->exists())->toBeTrue()
        ->and($p->detalles()->sum('cantidad_anulada'))->toEqual(0);
});

test('replaying an old authorized full cancellation cannot release a new table occupancy', function () {
    $p = pedidoSeguro($this, 'pedido');
    $plan = planSeguro($this, 'pedido', $p);
    ejecutarSeguro($this, 'pedido', $p, $plan);
    $nuevo = pedidoSeguro($this, 'borrador');
    ejecutarSeguro($this, 'pedido', $p, $plan);
    expect(OcupacionMesa::where('mesa_id', $this->mesa->id)->firstOrFail()->pedido_id)->toBe($nuevo->id)->and($nuevo->fresh()->estado->value)->toBe('abierto');
});

test('removing one unconfirmed row still requires no key and does not release its table', function () {
    $p = pedidoSeguro($this, 'borrador');
    $ui = Livewire::test(PedidoPantalla::class, ['pedido' => $p]);
    $clave = array_key_first($ui->get('filas'));
    $ui->call('removeRow', $clave)->call('save')->assertHasNoErrors();
    expect($p->detalles()->count())->toBe(1)->and($this->mesa->ocupacion()->exists())->toBeTrue()->and(MovimientoInventario::count())->toBe(0);
});

test('canceling the confirmation invalidates the reviewed whole or draft operation', function (string $tipo) {
    $p = pedidoSeguro($this, $tipo);
    $ui = Livewire::test(PedidoPantalla::class, ['pedido' => $p])->call($tipo === 'pedido' ? 'openCancelOrder' : 'openDiscard')
        ->set('motivoAnulacionId', (string) $this->autorizacion['motivoId'])->call($tipo === 'pedido' ? 'reviewCancelOrder' : 'requestDiscard')
        ->call('cancelarConfirmacionAnulacion')->assertSet('showConfirmCancel', false)
        ->call($tipo === 'pedido' ? 'confirmCancelOrder' : 'confirmDiscard', $this->autorizacion['claveAutorizacion'])->assertHasErrors('seguridad');
    expect($this->mesa->ocupacion()->exists())->toBeTrue()->and(RegistroAnulacion::count())->toBe(0);
})->with(['pedido', 'borrador']);
