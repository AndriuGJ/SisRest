<?php

use App\Livewire\Pos\PedidoPantalla;
use App\Livewire\Pos\Pos;
use App\Models\Articulo;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Mesa;
use App\Models\MovimientoInventario;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\ReservaInventario;
use App\Models\Salon;
use App\Models\TurnoCaja;
use App\Models\User;
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
    $this->turno = TurnoCaja::create(['caja_id' => Caja::firstOrFail()->id, 'usuario_id' => $this->actor->id, 'fondo_inicial' => '100', 'abierta_at' => now()])->refresh();
    $this->mesa = Mesa::create(['salon_id' => Salon::firstOrFail()->id, 'codigo' => 'M01', 'capacidad' => 4])->refresh();
    $this->producto = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    $this->producto->articulo->update(['stock_actual' => '10']);
    Articulo::where('codigo', 'EJ-GASEOSA-STOCK')->update(['stock_actual' => '12']);
});

function datosAperturaPos(User $actor, ?Mesa $mesa = null, array $cambios = []): array
{
    return array_replace(['modalidad' => $mesa ? 'mesa' : 'rapida', 'mesaId' => $mesa ? (string) $mesa->id : '', 'mozoId' => (string) $actor->id, 'comensales' => '', 'observaciones' => '', 'destinatario' => '', 'telefono' => '', 'direccion' => '', 'referencia' => '', 'costoEnvio' => '0'], $cambios);
}
function abrirPedidoPos(User $actor, TurnoCaja $turno, ?Mesa $mesa = null, array $cambios = [], ?string $clave = null): Pedido
{
    return app(GestionPedidos::class)->abrir($actor, $turno->id, $clave ?? (string) Str::uuid(), datosAperturaPos($actor, $mesa, $cambios))->refresh();
}
function filaPos(Producto $producto, array $cambios = []): array
{
    return array_replace(['detalleId' => '', 'productoId' => (string) $producto->id, 'cantidad' => '1', 'nota' => ''], $cambios);
}
function guardarPos(User $actor, Pedido $pedido, TurnoCaja $turno, array $filas, array $cambios = []): Pedido
{
    return app(GestionPedidos::class)->guardar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido->fresh()), array_replace(['clienteId' => '', 'comensales' => '', 'observaciones' => ''], $cambios), $filas);
}

test('POS requires consultation and action permissions while photos work for authorized POS users', function () {
    auth()->logout();
    $this->get(route('pos.index'))->assertRedirect(route('login'));
    $u = User::factory()->create();
    $u->givePermissionTo('pos.ver');
    $this->actingAs($u)->get(route('pos.index'))->assertForbidden();
    $u->givePermissionTo('pedidos.ver_propios');
    $this->get(route('pos.index'))->assertOk();
    expect(fn () => abrirPedidoPos($u, $this->turno, $this->mesa))->toThrow(AuthorizationException::class);
    Livewire::test(Pos::class)->call('openForm', 'rapida')->assertForbidden();
    // La ruta de fotografía ya permite POS sin conceder acceso al catálogo administrativo.
    $this->get(route('productos.imagen', $this->producto))->assertNotFound();
    $this->get(route('productos.index'))->assertForbidden();
});

test('opening a table requires a live box occupies it once and records origin and responsible snapshots', function () {
    $clave = (string) Str::uuid();
    $pedido = abrirPedidoPos($this->actor, $this->turno, $this->mesa, ['comensales' => '3'], $clave);
    expect(abrirPedidoPos($this->actor, $this->turno, $this->mesa, [], $clave)->id)->toBe($pedido->id)->and(Pedido::count())->toBe(1)
        ->and(OcupacionMesa::first()->pedido_id)->toBe($pedido->id)->and($pedido->turno_apertura_id)->toBe($this->turno->id)->and($pedido->mesa_codigo)->toBe('M01')->and($pedido->comensales)->toBe(3);
    expect(fn () => abrirPedidoPos($this->actor, $this->turno, $this->mesa))->toThrow(ValidationException::class);
    $this->get(route('pos.index'))->assertOk()->assertSee('Ocupada');
    $this->get(route('pos.pedido', $pedido))->assertOk()->assertSee('Mesa M01');
});

test('closed boxes inactive tables and inactive rooms cannot open an attention', function () {
    $this->mesa->update(['activo' => false]);
    expect(fn () => abrirPedidoPos($this->actor, $this->turno, $this->mesa))->toThrow(ValidationException::class);
    $this->mesa->update(['activo' => true]);
    $this->mesa->salon->update(['activo' => false]);
    expect(fn () => abrirPedidoPos($this->actor, $this->turno, $this->mesa))->toThrow(ValidationException::class);
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    expect(fn () => abrirPedidoPos($this->actor, $this->turno))->toThrow(ValidationException::class);
    expect(Pedido::count())->toBe(0)->and(OcupacionMesa::count())->toBe(0);
});

test('legacy active orders without an occupancy still block opening the same table', function () {
    Pedido::create(['codigo' => 'LEGACY', 'modalidad' => 'mesa', 'mesa_id' => $this->mesa->id, 'mozo_id' => $this->actor->id, 'creado_por' => $this->actor->id, 'abierto_at' => now()]);
    expect(fn () => abrirPedidoPos($this->actor, $this->turno, $this->mesa))->toThrow(ValidationException::class);
});

test('waiters only see their orders while a cashier may assign a waiter without exposing cash balances', function () {
    $mozo = User::factory()->create(['name' => 'Mozo propio']);
    $mozo->assignRole('mozo');
    $otro = User::factory()->create(['name' => 'Mozo ajeno']);
    $otro->assignRole('mozo');
    $pedido = abrirPedidoPos($this->actor, $this->turno, $this->mesa, ['mozoId' => (string) $otro->id]);
    $this->actingAs($mozo)->get(route('pos.index'))->assertOk()->assertDontSee($pedido->codigo)->assertDontSee('Mozo ajeno')->assertDontSee('100.00');
    $this->get(route('pos.pedido', $pedido))->assertForbidden();
    expect(fn () => guardarPos($mozo, $pedido, $this->turno, []))->toThrow(HttpException::class);
    expect(fn () => abrirPedidoPos($mozo, $this->turno, null, ['mozoId' => (string) $otro->id]))->toThrow(HttpException::class);
    $mio = abrirPedidoPos($mozo, $this->turno);
    $this->get(route('pos.pedido', $mio))->assertOk();
});

test('rapid and delivery open without tables and delivery requires contact and exact shipping cost', function () {
    $rapida = abrirPedidoPos($this->actor, $this->turno);
    expect($rapida->modalidad->value)->toBe('rapida')->and($rapida->mesa_id)->toBeNull();
    expect(fn () => abrirPedidoPos($this->actor, $this->turno, null, ['modalidad' => 'delivery']))->toThrow(ValidationException::class);
    $delivery = abrirPedidoPos($this->actor, $this->turno, null, ['modalidad' => 'delivery', 'destinatario' => 'Cliente delivery', 'telefono' => '999111222', 'direccion' => 'Av. Principal 123', 'costoEnvio' => '5.50']);
    expect($delivery->delivery->costo_envio)->toBe('5.50')->and(OcupacionMesa::count())->toBe(0);
    expect(fn () => abrirPedidoPos($this->actor, $this->turno, $this->mesa, ['modalidad' => 'rapida']))->toThrow(ValidationException::class);
});

test('draft products quantities notes and server prices save without reserving or moving inventory', function () {
    $pedido = abrirPedidoPos($this->actor, $this->turno, $this->mesa);
    guardarPos($this->actor, $pedido, $this->turno, ['uno' => filaPos($this->producto, ['cantidad' => '2', 'nota' => 'Sin cebolla'])]);
    $detalle = $pedido->detalles()->firstOrFail();
    expect($detalle->precio_unitario)->toBe('20.00')->and($detalle->cantidad)->toBe('2.0000')->and($detalle->nota)->toBe('Sin cebolla')
        ->and($detalle->estado->value)->toBe('borrador')->and($this->producto->articulo->fresh()->stock_actual)->toBe('10.0000')->and($this->producto->articulo->fresh()->stock_reservado)->toBe('0.0000')
        ->and(ReservaInventario::count())->toBe(0)->and(MovimientoInventario::count())->toBe(0);
    $this->producto->update(['precio_venta' => '99']);
    guardarPos($this->actor, $pedido, $this->turno, ['uno' => filaPos($this->producto, ['detalleId' => (string) $detalle->id, 'cantidad' => '3'])]);
    expect($detalle->fresh()->precio_unitario)->toBe('20.00');
    $this->get(route('pos.pedido', $pedido))->assertOk()->assertSee('S/ 60.00');
});

test('forged prices external line ids and products missing from POS are rejected', function () {
    $pedido = abrirPedidoPos($this->actor, $this->turno);
    expect(fn () => guardarPos($this->actor, $pedido, $this->turno, ['a' => filaPos($this->producto, ['precio' => '0.01'])]))->toThrow(ValidationException::class);
    $ajeno = abrirPedidoPos($this->actor, $this->turno);
    guardarPos($this->actor, $ajeno, $this->turno, ['a' => filaPos($this->producto)]);
    $linea = $ajeno->detalles()->firstOrFail();
    expect(fn () => guardarPos($this->actor, $pedido, $this->turno, ['a' => filaPos($this->producto, ['detalleId' => (string) $linea->id])]))->toThrow(ValidationException::class);
    $this->producto->update(['visible_pos' => false]);
    expect(fn () => guardarPos($this->actor, $pedido, $this->turno, ['a' => filaPos($this->producto)]))->toThrow(ValidationException::class);
    expect($pedido->detalles()->count())->toBe(0);
});

test('quantity validation and combined availability are authoritative when saving', function (string $cantidad) {
    $pedido = abrirPedidoPos($this->actor, $this->turno);
    expect(fn () => guardarPos($this->actor, $pedido, $this->turno, ['a' => filaPos($this->producto, ['cantidad' => $cantidad])]))->toThrow(ValidationException::class);
    expect($pedido->detalles()->count())->toBe(0);
})->with(['0', '-1', '1.5', '1e2', '11', '10000']);

test('combos snapshot components and share stock checks with individually ordered components', function () {
    $pedido = abrirPedidoPos($this->actor, $this->turno);
    $combo = Producto::where('codigo', 'EJ-COMBO')->firstOrFail();
    expect(fn () => guardarPos($this->actor, $pedido, $this->turno, ['a' => filaPos($combo, ['cantidad' => '6']), 'b' => filaPos($this->producto, ['cantidad' => '5'])]))->toThrow(ValidationException::class);
    guardarPos($this->actor, $pedido, $this->turno, ['a' => filaPos($combo, ['cantidad' => '2'])]);
    $padre = $pedido->detalles()->whereNull('detalle_padre_id')->firstOrFail();
    expect($padre->hijos()->count())->toBe(2)->and($padre->hijos->first()->precio_unitario)->toBe('0.00')->and($padre->hijos->first()->factor_componente)->toBe('1.0000');
    $combo->componentes()->where('producto_id', $this->producto->id)->update(['cantidad' => '2']);
    guardarPos($this->actor, $pedido, $this->turno, ['a' => filaPos($combo, ['detalleId' => (string) $padre->id, 'cantidad' => '3'])]);
    expect($padre->hijos()->where('producto_id', $this->producto->id)->first()->cantidad)->toBe('3.0000')->and(ReservaInventario::count())->toBe(0);
});

test('stale sessions cannot overwrite cart edits and closed shifts require another live operation box', function () {
    $pedido = abrirPedidoPos($this->actor, $this->turno);
    $viejo = Livewire::test(PedidoPantalla::class, ['pedido' => $pedido]);
    guardarPos($this->actor, $pedido, $this->turno, ['a' => filaPos($this->producto)]);
    $viejo->call('save')->assertHasErrors('seguridad');
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    expect(fn () => guardarPos($this->actor, $pedido, $this->turno, []))->toThrow(ValidationException::class);
    $otra = TurnoCaja::create(['caja_id' => Caja::whereKeyNot($this->turno->caja_id)->first()->id, 'usuario_id' => $this->actor->id, 'abierta_at' => now()])->refresh();
    guardarPos($this->actor, $pedido, $otra, []);
    expect($pedido->fresh()->turno_apertura_id)->toBe($this->turno->id);
});

test('optional customer assignment requires consultation and an active client', function () {
    $pedido = abrirPedidoPos($this->actor, $this->turno);
    $cliente = Cliente::create(['nombre' => 'Cliente POS', 'activo' => false])->refresh();
    expect(fn () => guardarPos($this->actor, $pedido, $this->turno, [], ['clienteId' => (string) $cliente->id]))->toThrow(ValidationException::class);
    $cliente->update(['activo' => true]);
    guardarPos($this->actor, $pedido, $this->turno, [], ['clienteId' => (string) $cliente->id]);
    expect($pedido->fresh()->cliente_id)->toBe($cliente->id);
    $mozo = User::factory()->create();
    $mozo->assignRole('mozo');
    $mio = abrirPedidoPos($mozo, $this->turno);
    expect(fn () => guardarPos($mozo, $mio, $this->turno, [], ['clienteId' => (string) $cliente->id]))->toThrow(AuthorizationException::class);
});

test('discarding an unconfirmed draft preserves history and frees its table even after the box closes', function () {
    $pedido = abrirPedidoPos($this->actor, $this->turno, $this->mesa);
    guardarPos($this->actor, $pedido, $this->turno, ['a' => filaPos($this->producto)]);
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    Livewire::test(PedidoPantalla::class, ['pedido' => $pedido->fresh()])->set('motivo', 'Cliente se retiró')->call('requestDiscard')->assertDispatched('app:confirm')->call('confirmDiscard')->assertHasNoErrors();
    expect($pedido->fresh()->estado->value)->toBe('anulado')->and($pedido->detalles()->first()->estado->value)->toBe('anulado')->and(OcupacionMesa::count())->toBe(0)
        ->and($this->producto->articulo->fresh()->stock_actual)->toBe('10.0000')->and(MovimientoInventario::count())->toBe(0);
});

test('confirmed lines remain read only and prevent discarding but allow adding new draft products', function () {
    $pedido = abrirPedidoPos($this->actor, $this->turno);
    guardarPos($this->actor, $pedido, $this->turno, ['a' => filaPos($this->producto)]);
    $linea = $pedido->detalles()->firstOrFail();
    $linea->update(['estado' => 'confirmado', 'confirmado_at' => now()]);
    guardarPos($this->actor, $pedido, $this->turno, ['b' => filaPos($this->producto, ['cantidad' => '2'])]);
    expect($linea->fresh()->cantidad)->toBe('1.0000')->and($pedido->detalles()->count())->toBe(2);
    expect(fn () => app(GestionPedidos::class)->descartar($this->actor, $pedido->id, GestionPedidos::huella($pedido->fresh()), 'Cancelar pedido'))->toThrow(ValidationException::class);
    expect(fn () => guardarPos($this->actor, $pedido, $this->turno, ['a' => filaPos($this->producto, ['detalleId' => (string) $linea->id])]))->toThrow(ValidationException::class);
});

test('livewire stores a draft and protects identifiers permissions and product filtering', function () {
    $componente = Livewire::test(Pos::class)->call('openForm', 'mesa', $this->mesa->id)->call('openOrder')->assertHasNoErrors();
    $pedido = Pedido::firstOrFail();
    Livewire::test(PedidoPantalla::class, ['pedido' => $pedido])->call('addProduct', $this->producto->id)->call('save')->assertHasNoErrors();
    expect($pedido->detalles()->count())->toBe(1);
    expect(fn () => Livewire::test(PedidoPantalla::class, ['pedido' => $pedido])->set('pedidoId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
    $form = Livewire::test(PedidoPantalla::class, ['pedido' => $pedido->fresh()]);
    $this->actor->roles->first()->revokePermissionTo('pedidos.editar');
    $this->actor->unsetRelations();
    $form->call('save')->assertForbidden();
});
