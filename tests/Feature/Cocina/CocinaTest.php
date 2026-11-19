<?php

use App\Livewire\Cocina\Cocina;
use App\Livewire\Pos\PedidoPantalla;
use App\Models\AreaPreparacion;
use App\Models\Articulo;
use App\Models\Caja;
use App\Models\ConfirmacionPedido;
use App\Models\EnvioCocina;
use App\Models\EnvioCocinaDetalle;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\ReservaInventario;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Cocina\GestionCocina;
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
    $this->turno = TurnoCaja::create(['caja_id' => Caja::firstOrFail()->id, 'usuario_id' => $this->actor->id, 'fondo_inicial' => '100', 'abierta_at' => now()])->refresh();
    $this->producto = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    $this->gaseosa = Producto::where('codigo', 'EJ-GASEOSA')->firstOrFail();
    $this->combo = Producto::where('tipo', 'combo')->firstOrFail();
    $this->producto->articulo->update(['stock_actual' => '10', 'costo_promedio' => '8']);
    $this->gaseosa->articulo->update(['stock_actual' => '12']);
});

function pedidoCocina(User $actor, TurnoCaja $turno, array $productos): Pedido
{
    $pedido = app(GestionPedidos::class)->abrir($actor, $turno->id, (string) Str::uuid(), ['modalidad' => 'rapida', 'mesaId' => '', 'mozoId' => (string) $actor->id, 'comensales' => '', 'observaciones' => 'Sin picante', 'destinatario' => '', 'telefono' => '', 'direccion' => '', 'referencia' => '', 'costoEnvio' => '0']);

    return agregarCocina($actor, $pedido, $turno, $productos);
}
function agregarCocina(User $actor, Pedido $pedido, TurnoCaja $turno, array $productos): Pedido
{
    $filas = [];
    foreach ($productos as $producto => $cantidad) {
        $filas['n-'.$producto] = ['detalleId' => '', 'productoId' => (string) $producto, 'cantidad' => (string) $cantidad, 'nota' => 'Sin cebolla'];
    }

    return app(GestionPedidos::class)->guardar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido->fresh()), ['clienteId' => '', 'comensales' => '', 'observaciones' => 'Sin picante'], $filas)->refresh();
}
function confirmarCocina(User $actor, Pedido $pedido, TurnoCaja $turno, ?string $clave = null): ConfirmacionPedido
{
    return app(ConfirmarPedido::class)->confirmar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido->fresh()), $clave ?? (string) Str::uuid());
}
function avanzarCocina(User $actor, EnvioCocinaDetalle $detalle, string $accion, string $cantidad = '1'): void
{
    app(GestionCocina::class)->avanzar($actor, $detalle->id, $accion, $cantidad, GestionCocina::huella($detalle->fresh()));
}
function entregarCocina(User $actor, Pedido $pedido, int $lineaId, string $cantidad = '1'): void
{
    app(GestionEntregas::class)->entregar($actor, $pedido->id, $lineaId, $cantidad, GestionPedidos::huella($pedido->fresh()));
}

test('confirmation reserves prepared and resale quantities without consuming ingredients stock or cash', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 2, $this->gaseosa->id => 1]);
    $antes = Articulo::where('tipo', 'insumo')->pluck('stock_actual', 'id')->all();
    $lote = confirmarCocina($this->actor, $pedido, $this->turno);
    expect($lote->numero_lote)->toBe(1)->and($lote->turno_caja_id)->toBe($this->turno->id)
        ->and($this->producto->articulo->fresh()->stock_actual)->toBe('10.0000')->and($this->producto->articulo->fresh()->stock_reservado)->toBe('2.0000')
        ->and($this->gaseosa->articulo->fresh()->stock_reservado)->toBe('1.0000')->and(ReservaInventario::count())->toBe(2)
        ->and(EnvioCocinaDetalle::count())->toBe(1)->and(MovimientoInventario::count())->toBe(0)->and(MovimientoCaja::count())->toBe(0)
        ->and(Articulo::where('tipo', 'insumo')->pluck('stock_actual', 'id')->all())->toBe($antes);
    $envio = EnvioCocina::firstOrFail();
    expect($envio->area_nombre)->toBe('Cocina')->and($envio->detalles->first()->nota)->toBe('Sin cebolla')->and($pedido->fresh()->estado->value)->toBe('en_preparacion');
});

test('repeating confirmation UUID returns the same result even with a closed shift but rejects reuse on another order', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 1]);
    $otro = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 1]);
    $clave = (string) Str::uuid();
    $version = GestionPedidos::huella($pedido);
    $lote = app(ConfirmarPedido::class)->confirmar($this->actor, $pedido->id, $this->turno->id, $version, $clave);
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    expect(app(ConfirmarPedido::class)->confirmar($this->actor, $pedido->id, $this->turno->id, $version, $clave)->id)->toBe($lote->id)
        ->and(ConfirmacionPedido::count())->toBe(1)->and(ReservaInventario::count())->toBe(1);
    expect(fn () => app(ConfirmarPedido::class)->confirmar($this->actor, $otro->id, $this->turno->id, '', $clave))->toThrow(HttpException::class);
});

test('insufficient aggregate stock rolls back every reservation and shipment', function () {
    $uno = pedidoCocina($this->actor, $this->turno, [$this->combo->id => 6]);
    $dos = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 5, $this->gaseosa->id => 1]);
    confirmarCocina($this->actor, $uno, $this->turno);
    expect(fn () => confirmarCocina($this->actor, $dos, $this->turno))->toThrow(ValidationException::class);
    expect($dos->detalles()->where('estado', 'confirmado')->count())->toBe(0)->and(ConfirmacionPedido::count())->toBe(1)
        ->and($this->producto->articulo->fresh()->stock_reservado)->toBe('6.0000')->and($this->gaseosa->articulo->fresh()->stock_reservado)->toBe('6.0000');
});

test('confirmation checks closed cash active catalog destinations empty orders and stale versions', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, []);
    expect(fn () => confirmarCocina($this->actor, $pedido, $this->turno))->toThrow(ValidationException::class);
    $pedido = agregarCocina($this->actor, $pedido, $this->turno, [$this->producto->id => 1]);
    $version = GestionPedidos::huella($pedido);
    $pedido->update(['observaciones' => 'Cambio de otro equipo']);
    expect(fn () => app(ConfirmarPedido::class)->confirmar($this->actor, $pedido->id, $this->turno->id, $version, (string) Str::uuid()))->toThrow(ValidationException::class);
    $this->producto->update(['visible_pos' => false]);
    expect(fn () => confirmarCocina($this->actor, $pedido, $this->turno))->toThrow(ValidationException::class);
    $this->producto->update(['visible_pos' => true]);
    $this->producto->areaPreparacion->update(['activo' => false]);
    expect(fn () => confirmarCocina($this->actor, $pedido, $this->turno))->toThrow(ValidationException::class);
    $this->producto->areaPreparacion->update(['activo' => true]);
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    expect(fn () => confirmarCocina($this->actor, $pedido, $this->turno))->toThrow(ValidationException::class);
    expect(ConfirmacionPedido::count())->toBe(0)->and(ReservaInventario::count())->toBe(0);
});

test('saved combo composition price notes and destinations survive catalog changes', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->combo->id => 2]);
    $this->combo->componentes()->where('producto_id', $this->producto->id)->update(['cantidad' => '4']);
    $barra = AreaPreparacion::where('codigo', 'barra')->firstOrFail();
    $barra->update(['activo' => true]);
    $this->producto->update(['precio_venta' => '90', 'area_preparacion_id' => $barra->id]);
    confirmarCocina($this->actor, $pedido, $this->turno);
    expect($pedido->detalles()->whereNull('detalle_padre_id')->first()->precio_unitario)->toBe('25.00')
        ->and($this->producto->articulo->fresh()->stock_reservado)->toBe('2.0000')->and(ReservaInventario::count())->toBe(2)
        ->and(EnvioCocina::first()->area_nombre)->toBe('Cocina');
});

test('one confirmation splits kitchen and bar while direct products get no shipment', function () {
    $barra = AreaPreparacion::where('codigo', 'barra')->firstOrFail();
    $barra->update(['activo' => true]);
    $this->gaseosa->update(['area_preparacion_id' => $barra->id]);
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 2, $this->gaseosa->id => 1]);
    confirmarCocina($this->actor, $pedido, $this->turno);
    expect(EnvioCocina::count())->toBe(2)->and(EnvioCocina::pluck('numero_envio')->unique()->all())->toBe([1]);
    $this->get(route('cocina.index'))->assertOk()->assertSee('Cocina')->assertSee('Barra')->assertDontSee('S/ 20');
    Livewire::test(Cocina::class)->set('areaId', (string) $barra->id)->assertSee('Gaseosa (ejemplo)')->assertDontSee('Chaufa (ejemplo)');
});

test('a new batch keeps old kitchen progress and sends only its new items', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 2]);
    confirmarCocina($this->actor, $pedido, $this->turno);
    $viejo = EnvioCocinaDetalle::firstOrFail();
    avanzarCocina($this->actor, $viejo, 'iniciar', '2');
    avanzarCocina($this->actor, $viejo, 'listo', '1');
    $pedido = agregarCocina($this->actor, $pedido, $this->turno, [$this->producto->id => 1]);
    $segundo = confirmarCocina($this->actor, $pedido, $this->turno);
    expect($segundo->numero_lote)->toBe(2)->and(EnvioCocina::count())->toBe(2)->and($viejo->fresh()->cantidad_iniciada)->toBe('2.0000')
        ->and($viejo->fresh()->cantidad_lista)->toBe('1.0000')->and($this->producto->articulo->fresh()->stock_reservado)->toBe('3.0000');
});

test('partial preparation and ready quantities enforce sequence and stale actions cannot repeat', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 3]);
    confirmarCocina($this->actor, $pedido, $this->turno);
    $linea = EnvioCocinaDetalle::firstOrFail();
    expect(fn () => avanzarCocina($this->actor, $linea, 'listo'))->toThrow(ValidationException::class);
    $version = GestionCocina::huella($linea);
    avanzarCocina($this->actor, $linea, 'iniciar', '2');
    expect(fn () => app(GestionCocina::class)->avanzar($this->actor, $linea->id, 'iniciar', '1', $version))->toThrow(ValidationException::class);
    expect(fn () => avanzarCocina($this->actor, $linea, 'iniciar', '2'))->toThrow(ValidationException::class);
    avanzarCocina($this->actor, $linea, 'listo', '1');
    expect($linea->fresh()->cantidad_iniciada)->toBe('2.0000')->and($linea->fresh()->cantidad_lista)->toBe('1.0000')->and($pedido->fresh()->estado->value)->toBe('en_preparacion');
    avanzarCocina($this->actor, $linea, 'iniciar', '1');
    avanzarCocina($this->actor, $linea, 'listo', '2');
    expect($pedido->fresh()->estado->value)->toBe('listo')->and(MovimientoInventario::count())->toBe(0);
});

test('direct delivery and kitchen delivery consume only reserved sale stock with correct movement', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 2, $this->gaseosa->id => 1]);
    confirmarCocina($this->actor, $pedido, $this->turno);
    $chaufa = $pedido->detalles()->where('producto_id', $this->producto->id)->firstOrFail();
    $gaseosa = $pedido->detalles()->where('producto_id', $this->gaseosa->id)->firstOrFail();
    expect(fn () => entregarCocina($this->actor, $pedido, $chaufa->id))->toThrow(ValidationException::class);
    entregarCocina($this->actor, $pedido, $gaseosa->id);
    expect($this->gaseosa->articulo->fresh()->stock_actual)->toBe('11.0000')->and($this->gaseosa->articulo->fresh()->stock_reservado)->toBe('0.0000');
    $linea = EnvioCocinaDetalle::firstOrFail();
    avanzarCocina($this->actor, $linea, 'iniciar', '2');
    avanzarCocina($this->actor, $linea, 'listo', '1');
    entregarCocina($this->actor, $pedido, $chaufa->id);
    expect($chaufa->fresh()->cantidad_entregada)->toBe('1.0000')->and($this->producto->articulo->fresh()->stock_actual)->toBe('9.0000')
        ->and($this->producto->articulo->fresh()->stock_reservado)->toBe('1.0000')->and($linea->fresh()->cantidad_entregada)->toBe('1.0000');
    $mov = MovimientoInventario::where('articulo_id', $this->producto->articulo_id)->firstOrFail();
    expect($mov->cantidad)->toBe('-1.0000')->and($mov->costo_unitario)->toBe('8.000000')->and($mov->saldo_anterior)->toBe('10.0000')->and($mov->saldo_posterior)->toBe('9.0000');
    expect(fn () => entregarCocina($this->actor, $pedido, $chaufa->id))->toThrow(ValidationException::class);
    avanzarCocina($this->actor, $linea, 'listo', '1');
    entregarCocina($this->actor, $pedido, $chaufa->id);
    expect($pedido->fresh()->estado->value)->toBe('entregado')->and($this->producto->articulo->fresh()->stock_reservado)->toBe('0.0000')
        ->and(ReservaInventario::where('pedido_detalle_id', $chaufa->id)->first()->cantidad_consumida)->toBe('2.0000')->and(MovimientoCaja::count())->toBe(0);
});

test('delivery retry stale versions quantity excess and draft lines cannot duplicate stock movements', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->gaseosa->id => 2]);
    $detalle = $pedido->detalles()->firstOrFail();
    expect(fn () => entregarCocina($this->actor, $pedido, $detalle->id))->toThrow(ValidationException::class);
    confirmarCocina($this->actor, $pedido, $this->turno);
    $version = GestionPedidos::huella($pedido->fresh());
    app(GestionEntregas::class)->entregar($this->actor, $pedido->id, $detalle->id, '1', $version);
    expect(fn () => app(GestionEntregas::class)->entregar($this->actor, $pedido->id, $detalle->id, '1', $version))->toThrow(ValidationException::class);
    expect(fn () => entregarCocina($this->actor, $pedido, $detalle->id, '2'))->toThrow(ValidationException::class);
    expect(MovimientoInventario::count())->toBe(1)->and($this->gaseosa->articulo->fresh()->stock_actual)->toBe('11.0000');
});

test('combo parent records complete delivered units without another reservation or movement', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->combo->id => 2]);
    confirmarCocina($this->actor, $pedido, $this->turno);
    $padre = $pedido->detalles()->whereNull('detalle_padre_id')->firstOrFail();
    $chaufa = $padre->hijos()->where('producto_id', $this->producto->id)->firstOrFail();
    $gaseosa = $padre->hijos()->where('producto_id', $this->gaseosa->id)->firstOrFail();
    expect(fn () => entregarCocina($this->actor, $pedido, $padre->id))->toThrow(ValidationException::class);
    entregarCocina($this->actor, $pedido, $gaseosa->id, '2');
    expect($padre->fresh()->cantidad_entregada)->toBe('0.0000');
    $linea = EnvioCocinaDetalle::firstOrFail();
    avanzarCocina($this->actor, $linea, 'iniciar', '2');
    avanzarCocina($this->actor, $linea, 'listo', '2');
    entregarCocina($this->actor, $pedido, $chaufa->id, '1');
    expect($padre->fresh()->cantidad_entregada)->toBe('1.0000')->and(ReservaInventario::count())->toBe(2)->and(MovimientoInventario::count())->toBe(2);
    entregarCocina($this->actor, $pedido, $chaufa->id, '1');
    expect($padre->fresh()->cantidad_entregada)->toBe('2.0000')->and($pedido->fresh()->estado->value)->toBe('entregado');
});

test('closed shift does not stop preparation and physical delivery or create a payment', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 1]);
    confirmarCocina($this->actor, $pedido, $this->turno);
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    $linea = EnvioCocinaDetalle::firstOrFail();
    avanzarCocina($this->actor, $linea, 'iniciar');
    avanzarCocina($this->actor, $linea, 'listo');
    entregarCocina($this->actor, $pedido, $linea->pedido_detalle_id);
    expect($pedido->fresh()->estado->value)->toBe('entregado')->and($pedido->cobros()->count())->toBe(0)->and(MovimientoCaja::count())->toBe(0);
});

test('roles isolate kitchen preparation ready delivery and order ownership', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 1]);
    $cocinero = User::factory()->create();
    $cocinero->assignRole('cocina');
    expect(fn () => confirmarCocina($cocinero, $pedido, $this->turno))->toThrow(AuthorizationException::class);
    confirmarCocina($this->actor, $pedido, $this->turno);
    $linea = EnvioCocinaDetalle::firstOrFail();
    $this->actingAs($cocinero)->get(route('cocina.index'))->assertOk();
    $this->get(route('pos.pedido', $pedido))->assertForbidden();
    avanzarCocina($cocinero, $linea, 'iniciar');
    avanzarCocina($cocinero, $linea, 'listo');
    expect(fn () => entregarCocina($cocinero, $pedido, $linea->pedido_detalle_id))->toThrow(AuthorizationException::class);
    $mozo = User::factory()->create();
    $mozo->assignRole('mozo');
    expect(fn () => confirmarCocina($mozo, $pedido, $this->turno))->toThrow(HttpException::class);
    expect(fn () => entregarCocina($mozo, $pedido, $linea->pedido_detalle_id))->toThrow(HttpException::class);
    $cocinero->update(['activo' => false]);
    expect(fn () => avanzarCocina($cocinero, $linea, 'listo'))->toThrow(HttpException::class);
});

test('kitchen action permissions are rechecked separately and cannot touch terminal orders', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 1]);
    confirmarCocina($this->actor, $pedido, $this->turno);
    $linea = EnvioCocinaDetalle::firstOrFail();
    $lector = User::factory()->create();
    $lector->givePermissionTo(['cocina.ver', 'cocina.marcar_listo']);
    expect(fn () => avanzarCocina($lector, $linea, 'iniciar'))->toThrow(AuthorizationException::class);
    avanzarCocina($this->actor, $linea, 'iniciar');
    avanzarCocina($lector, $linea, 'listo');
    $pedido->update(['estado' => 'cerrado', 'cerrado_at' => now()]);
    expect(fn () => avanzarCocina($this->actor, $linea, 'iniciar'))->toThrow(ValidationException::class);
    expect(fn () => entregarCocina($this->actor, $pedido, $linea->pedido_detalle_id))->toThrow(ValidationException::class);
});

test('confirmation and delivery use reviewed locked fields and reject unsaved cart changes', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->gaseosa->id => 2]);
    $id = $pedido->detalles()->firstOrFail()->id;
    $pantalla = Livewire::test(PedidoPantalla::class, ['pedido' => $pedido]);
    $pantalla->set('observaciones', 'Cambio sin guardar')->call('requestSend')->assertHasErrors('seguridad');
    $pantalla = Livewire::test(PedidoPantalla::class, ['pedido' => $pedido]);
    $pantalla->call('requestSend')->assertDispatched('app:confirm')->call('confirmSend')->assertRedirect(route('pos.pedido', $pedido));
    expect(ReservaInventario::count())->toBe(1);
    Livewire::test(PedidoPantalla::class, ['pedido' => $pedido->fresh()])->set('cantidadesEntrega.'.$id, '1')->call('requestDelivery', $id)
        ->assertDispatched('app:confirm')->set('cantidadesEntrega.'.$id, '2')->call('confirmDelivery')->assertRedirect(route('pos.pedido', $pedido));
    expect($pedido->detalles()->find($id)->cantidad_entregada)->toBe('1.0000');
    expect(fn () => Livewire::test(PedidoPantalla::class, ['pedido' => $pedido->fresh()])->set('turnoEnvio', 999))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('kitchen updates partial quantities through Livewire and removed permission is enforced', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 2]);
    confirmarCocina($this->actor, $pedido, $this->turno);
    $linea = EnvioCocinaDetalle::firstOrFail();
    $cocinero = User::factory()->create();
    $cocinero->givePermissionTo(['cocina.ver', 'cocina.preparar']);
    $this->actingAs($cocinero);
    $pantalla = Livewire::test(Cocina::class)->set('cantidades.'.$linea->id, '2')->call('avanzar', $linea->id, 'iniciar', GestionCocina::huella($linea));
    expect($linea->fresh()->cantidad_iniciada)->toBe('2.0000');
    $cocinero->revokePermissionTo('cocina.preparar');
    $pantalla->call('avanzar', $linea->id, 'iniciar', GestionCocina::huella($linea->fresh()))->assertForbidden();
});

test('fractional combo factors retain exact reservation and complete delivered units', function () {
    $this->combo->componentes()->where('producto_id', $this->producto->id)->update(['cantidad' => '1.5']);
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->combo->id => 2]);
    confirmarCocina($this->actor, $pedido, $this->turno);
    $padre = $pedido->detalles()->whereNull('detalle_padre_id')->firstOrFail();
    $linea = EnvioCocinaDetalle::firstOrFail();
    $gaseosa = $padre->hijos()->where('producto_id', $this->gaseosa->id)->firstOrFail();
    expect($this->producto->articulo->fresh()->stock_reservado)->toBe('3.0000');
    entregarCocina($this->actor, $pedido, $gaseosa->id, '2');
    avanzarCocina($this->actor, $linea, 'iniciar', '3');
    avanzarCocina($this->actor, $linea, 'listo', '1.5');
    entregarCocina($this->actor, $pedido, $linea->pedido_detalle_id, '1.5');
    expect($padre->fresh()->cantidad_entregada)->toBe('1.0000')->and($this->producto->articulo->fresh()->stock_actual)->toBe('8.5000')
        ->and($this->producto->articulo->fresh()->stock_reservado)->toBe('1.5000');
});

test('malformed quantity payloads are rejected by the UI without calling quantity arithmetic', function () {
    $pedido = pedidoCocina($this->actor, $this->turno, [$this->producto->id => 1]);
    confirmarCocina($this->actor, $pedido, $this->turno);
    $linea = EnvioCocinaDetalle::firstOrFail();
    Livewire::test(Cocina::class)->set('cantidades.'.$linea->id, ['invalida'])->call('avanzar', $linea->id, 'iniciar', GestionCocina::huella($linea))->assertDispatched('app:alert');
    Livewire::test(PedidoPantalla::class, ['pedido' => $pedido->fresh()])->set('cantidadesEntrega.'.$linea->pedido_detalle_id, ['invalida'])->call('requestDelivery', $linea->pedido_detalle_id)->assertHasErrors();
    expect($linea->fresh()->cantidad_iniciada)->toBe('0.0000')->and(MovimientoInventario::count())->toBe(0);
});
