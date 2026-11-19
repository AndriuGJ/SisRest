<?php

use App\Livewire\Cobros\CobroFormulario;
use App\Livewire\Pos\AccionesProducto;
use App\Livewire\Pos\PedidoPantalla;
use App\Models\AnulacionPedido;
use App\Models\Articulo;
use App\Models\BeneficioPedido;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\CobroDetalle;
use App\Models\EnvioCocinaDetalle;
use App\Models\MedioPago;
use App\Models\Mesa;
use App\Models\MotivoBeneficio;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\ReservaInventario;
use App\Models\Salon;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Cajas\GestionCajas;
use App\Services\Cobros\CerrarAtencion;
use App\Services\Cobros\EstadoCuenta;
use App\Services\Cobros\GestionCobros;
use App\Services\Cocina\GestionCocina;
use App\Services\Pos\ConfirmarPedido;
use App\Services\Pos\GestionAnulaciones;
use App\Services\Pos\GestionBeneficios;
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
    $this->plato = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    $this->bebida = Producto::where('codigo', 'EJ-GASEOSA')->firstOrFail();
    $this->combo = Producto::where('codigo', 'EJ-COMBO')->firstOrFail();
    $this->plato->articulo->update(['stock_actual' => '10', 'costo_promedio' => '8']);
    $this->bebida->articulo->update(['stock_actual' => '12']);
    $this->motivo = MotivoBeneficio::where('codigo', 'cumpleanos')->firstOrFail();
});
function pedidoBeneficio(User $actor, TurnoCaja $turno, array $productos, ?Mesa $mesa = null, ?string $envio = null): Pedido
{
    $pedido = app(GestionPedidos::class)->abrir($actor, $turno->id, (string) Str::uuid(), ['modalidad' => $envio !== null ? 'delivery' : ($mesa ? 'mesa' : 'rapida'), 'mesaId' => $mesa ? (string) $mesa->id : '', 'mozoId' => (string) $actor->id,
        'comensales' => '', 'observaciones' => '', 'destinatario' => 'Prueba', 'telefono' => '999444555', 'direccion' => 'Dirección prueba', 'referencia' => '', 'costoEnvio' => $envio ?? '0'])->refresh();
    $filas = [];
    foreach ($productos as $id => $q) {
        $filas['n-'.$id] = ['detalleId' => '', 'productoId' => (string) $id, 'cantidad' => (string) $q, 'nota' => ''];
    }
    app(GestionPedidos::class)->guardar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido), ['clienteId' => '', 'comensales' => '', 'observaciones' => ''], $filas);
    app(ConfirmarPedido::class)->confirmar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido->fresh()), (string) Str::uuid());

    return $pedido->fresh();
}
function datosBeneficio(int $motivo, array $cambios = []): array
{
    return array_replace(['accion' => 'descuento', 'tipo' => 'soles', 'valor' => '1', 'motivoId' => (string) $motivo, 'motivo' => 'Promoción autorizada de prueba'], $cambios);
}
function autorizarBeneficio(User $actor, Pedido $pedido, int $id, array $datos, ?string $clave = null): BeneficioPedido
{
    $servicio = app(GestionBeneficios::class);
    $plan = $servicio->previsualizar($actor, $pedido->id, $id, $datos);

    return $servicio->aplicar($actor, $pedido->id, $id, $clave ?? (string) Str::uuid(), $plan['huella'], $datos);
}
function anularBeneficio(User $actor, Pedido $pedido, int $id, string $cantidad, ?string $clave = null): AnulacionPedido
{
    $servicio = app(GestionAnulaciones::class);
    $plan = $servicio->previsualizar($actor, $pedido->id, $id, $cantidad, 'Cliente canceló el producto');

    return $servicio->anular($actor, $pedido->id, $id, $cantidad, 'Cliente canceló el producto', $clave ?? (string) Str::uuid(), $plan['huella']);
}
function cobroBeneficio(User $actor, Pedido $pedido, TurnoCaja $turno, array $cantidades, string $importe, array $extra = []): Cobro
{
    $datos = array_replace(['clienteId' => '', 'grupoPagador' => '', 'incluirEnvio' => false], $extra);
    $seleccion = [];
    foreach ($cantidades as $id => $q) {
        $seleccion['d-'.$id] = ['detalleId' => (string) $id, 'cantidad' => (string) $q];
    }
    $pagos = bccomp($importe, '0', 2) > 0 ? ['uno' => ['medioId' => (string) MedioPago::where('codigo', 'yape')->value('id'), 'monto' => $importe, 'entregado' => '', 'referencia' => '']] : [];
    $servicio = app(GestionCobros::class);
    $plan = $servicio->previsualizar($actor, $pedido->id, $turno->id, $datos, $seleccion, $pagos);

    return $servicio->cobrar($actor, $pedido->id, $turno->id, (string) Str::uuid(), $plan['huella'], $datos, $seleccion, $pagos);
}
function avanceBeneficio(User $actor, EnvioCocinaDetalle $linea, string $accion, string $q): void
{
    app(GestionCocina::class)->avanzar($actor, $linea->id, $accion, $q, GestionCocina::huella($linea->fresh()));
}

test('unstarted cancellation releases only its reservation and is idempotent without stock or cash movements', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 3]);
    $raiz = $pedido->detalles()->first();
    $clave = (string) Str::uuid();
    $plan = app(GestionAnulaciones::class)->previsualizar($this->actor, $pedido->id, $raiz->id, '2', 'Cliente canceló el producto');
    $registro = app(GestionAnulaciones::class)->anular($this->actor, $pedido->id, $raiz->id, '2', 'Cliente canceló el producto', $clave, $plan['huella']);
    expect(app(GestionAnulaciones::class)->anular($this->actor, $pedido->id, $raiz->id, '2', 'Cliente canceló el producto', $clave, $plan['huella'])->id)->toBe($registro->id)
        ->and($this->plato->articulo->fresh()->stock_actual)->toBe('10.0000')->and($this->plato->articulo->fresh()->stock_reservado)->toBe('1.0000')->and($raiz->fresh()->cantidad_anulada)->toBe('2.0000')
        ->and(ReservaInventario::first()->cantidad_liberada)->toBe('2.0000')->and(EnvioCocinaDetalle::first()->cantidad_anulada)->toBe('2.0000')->and(MovimientoInventario::count())->toBe(0)->and(MovimientoCaja::count())->toBe(0)->and(AnulacionPedido::count())->toBe(1);
    $this->get(route('pos.pedido', $pedido))->assertOk()->assertSee('Cliente canceló el producto')->assertSee('Anulados: 2');
});

test('started cancellation first uses unstarted units then records waste and keeps kitchen counters valid', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 5]);
    $raiz = $pedido->detalles()->first();
    $envio = EnvioCocinaDetalle::first();
    avanceBeneficio($this->actor, $envio, 'iniciar', '4');
    avanceBeneficio($this->actor, $envio, 'listo', '2');
    $insumos = Articulo::where('tipo', 'insumo')->pluck('stock_actual', 'id')->all();
    $registro = anularBeneficio($this->actor, $pedido, $raiz->id, '3');
    expect($this->plato->articulo->fresh()->stock_actual)->toBe('8.0000')->and($this->plato->articulo->fresh()->stock_reservado)->toBe('2.0000')->and($registro->detalles->first()->cantidad_liberada)->toBe('1.0000')->and($registro->detalles->first()->cantidad_merma)->toBe('2.0000')
        ->and($envio->fresh()->cantidad_iniciada)->toBe('2.0000')->and($envio->fresh()->cantidad_lista)->toBe('2.0000')->and($envio->fresh()->cantidad_anulada)->toBe('3.0000')->and($pedido->fresh()->estado->value)->toBe('listo')
        ->and(MovimientoInventario::first()->tipo->value)->toBe('merma')->and(MovimientoInventario::first()->cantidad)->toBe('-2.0000')->and(MovimientoInventario::first()->costo_unitario)->toBe('8.000000')->and(Articulo::where('tipo', 'insumo')->pluck('stock_actual', 'id')->all())->toBe($insumos);
});

test('ready cancellation decrements ready and started counters while preserving quantities already delivered', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 3]);
    $raiz = $pedido->detalles()->first();
    $envio = EnvioCocinaDetalle::first();
    avanceBeneficio($this->actor, $envio, 'iniciar', '3');
    avanceBeneficio($this->actor, $envio, 'listo', '3');
    app(GestionEntregas::class)->entregar($this->actor, $pedido->id, $raiz->id, '1', GestionPedidos::huella($pedido->fresh()));
    anularBeneficio($this->actor, $pedido, $raiz->id, '2');
    expect($envio->fresh()->cantidad_lista)->toBe('1.0000')->and($envio->fresh()->cantidad_iniciada)->toBe('1.0000')->and($envio->fresh()->cantidad_entregada)->toBe('1.0000')->and($this->plato->articulo->fresh()->stock_actual)->toBe('7.0000')->and($this->plato->articulo->fresh()->stock_reservado)->toBe('0.0000');
});

test('paid and delivered quantities cannot be canceled but their unpaid unserved remainder can', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->bebida->id => 3]);
    $raiz = $pedido->detalles()->first();
    $cobro = cobroBeneficio($this->actor, $pedido, $this->turno, [$raiz->id => 1], '6');
    app(GestionEntregas::class)->entregar($this->actor, $pedido->id, $raiz->id, '1', GestionPedidos::huella($pedido->fresh()));
    expect(fn () => anularBeneficio($this->actor, $pedido, $raiz->id, '3'))->toThrow(ValidationException::class);
    anularBeneficio($this->actor, $pedido, $raiz->id, '2');
    expect($cobro->fresh()->total)->toBe('6.00')->and($raiz->fresh()->cantidad_cobrada)->toBe('1.0000')->and($raiz->fresh()->cantidad_entregada)->toBe('1.0000')->and(EstadoCuenta::liquidada($pedido->fresh()))->toBeTrue();
    app(CerrarAtencion::class)->cerrar($this->actor, $pedido->id, EstadoCuenta::huella($pedido->fresh()));
    expect($pedido->fresh()->estado->value)->toBe('cerrado');
});

test('cancellation of a combo uses its historical factors and refuses component delivery overlap', function () {
    $this->combo->componentes()->where('producto_id', $this->plato->id)->update(['cantidad' => '1.5']);
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->combo->id => 2]);
    $raiz = $pedido->detalles()->whereNull('detalle_padre_id')->first();
    $this->combo->componentes()->where('producto_id', $this->plato->id)->update(['cantidad' => '2']);
    anularBeneficio($this->actor, $pedido, $raiz->id, '1');
    expect($this->plato->articulo->fresh()->stock_reservado)->toBe('1.5000')->and($this->bebida->articulo->fresh()->stock_reservado)->toBe('1.0000')->and($raiz->fresh()->cantidad_anulada)->toBe('1.0000');
    $bebida = $raiz->hijos()->where('producto_id', $this->bebida->id)->first();
    app(GestionEntregas::class)->entregar($this->actor, $pedido->id, $bebida->id, '1', GestionPedidos::huella($pedido->fresh()));
    expect(fn () => anularBeneficio($this->actor, $pedido, $raiz->id, '1'))->toThrow(ValidationException::class);
});

test('kitchen progress after cancellation review invalidates confirmation even when order status is unchanged', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 2]);
    $raiz = $pedido->detalles()->first();
    $s = app(GestionAnulaciones::class);
    $plan = $s->previsualizar($this->actor, $pedido->id, $raiz->id, '2', 'Cliente canceló el producto');
    avanceBeneficio($this->actor, EnvioCocinaDetalle::first(), 'iniciar', '1');
    expect(fn () => $s->anular($this->actor, $pedido->id, $raiz->id, '2', 'Cliente canceló el producto', (string) Str::uuid(), $plan['huella']))->toThrow(ValidationException::class);
    expect(AnulacionPedido::count())->toBe(0)->and($this->plato->articulo->fresh()->stock_reservado)->toBe('2.0000');
});

test('waste and cancellation require independent permissions and order scope', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 2]);
    $raiz = $pedido->detalles()->first();
    $delegado = User::factory()->create();
    $delegado->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'pedidos.anular_enviados']);
    avanceBeneficio($this->actor, EnvioCocinaDetalle::first(), 'iniciar', '2');
    expect(fn () => anularBeneficio($delegado, $pedido, $raiz->id, '1'))->toThrow(AuthorizationException::class);
    $delegado->givePermissionTo('inventario.registrar_merma');
    anularBeneficio($delegado, $pedido, $raiz->id, '1');
    $otro = User::factory()->create();
    $otro->givePermissionTo(['pos.ver', 'pedidos.ver_propios', 'pedidos.anular_enviados', 'inventario.registrar_merma']);
    expect(fn () => anularBeneficio($otro, $pedido, $raiz->id, '1'))->toThrow(HttpException::class);
});

test('fully canceled confirmed products can close manually without a fictitious payment or stock return', function () {
    $mesa = Mesa::create(['salon_id' => Salon::first()->id, 'codigo' => 'AN-1']);
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->bebida->id => 1], $mesa);
    $raiz = $pedido->detalles()->first();
    anularBeneficio($this->actor, $pedido, $raiz->id, '1');
    expect(EstadoCuenta::liquidada($pedido->fresh()))->toBeTrue();
    app(CerrarAtencion::class)->cerrar($this->actor, $pedido->id, EstadoCuenta::huella($pedido->fresh()));
    expect(Cobro::count())->toBe(0)->and($this->bebida->articulo->fresh()->stock_actual)->toBe('12.0000')->and($mesa->ocupacion)->toBeNull();
});

test('product fixed discount allocates all cents across split payments while preserving original prices', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 3]);
    $raiz = $pedido->detalles()->first();
    autorizarBeneficio($this->actor, $pedido, $raiz->id, datosBeneficio($this->motivo->id));
    foreach (['19.67', '19.66', '19.67'] as $importe) {
        cobroBeneficio($this->actor, $pedido, $this->turno, [$raiz->id => 1], $importe);
    }
    expect(CobroDetalle::pluck('descuento_producto')->all())->toBe(['0.33', '0.34', '0.33'])->and(Cobro::sum('total'))->toEqual(59)->and($raiz->fresh()->precio_unitario)->toBe('20.00')->and(MovimientoInventario::count())->toBe(0)->and(EstadoCuenta::liquidada($pedido->fresh()))->toBeTrue();
});

test('percentage product benefit keeps motive author and an immutable receipt snapshot', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 3]);
    $raiz = $pedido->detalles()->first();
    autorizarBeneficio($this->actor, $pedido, $raiz->id, datosBeneficio($this->motivo->id, ['tipo' => 'porcentaje', 'valor' => '12.5']));
    $cobro = cobroBeneficio($this->actor, $pedido, $this->turno, [$raiz->id => 1], '17.5');
    $this->motivo->update(['nombre' => 'Nuevo nombre']);
    expect($raiz->fresh()->descuento_importe)->toBe('7.50')->and($cobro->descuento_productos)->toBe('2.50')->and($cobro->detalles->first()->beneficio_resumen['motivo'])->toBe('Cumpleaños')->and($cobro->detalles->first()->precio_unitario)->toBe('20.00');
    $this->get(route('pos.cobro', $cobro))->assertOk()->assertSee('Cumpleaños')->assertSee('2.50');
});

test('courtesy still reserves and consumes stock on delivery and can be settled without cash or benefit permissions on cashier', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->bebida->id => 2]);
    $raiz = $pedido->detalles()->first();
    autorizarBeneficio($this->actor, $pedido, $raiz->id, datosBeneficio($this->motivo->id, ['accion' => 'cortesia', 'valor' => '0']));
    expect($this->bebida->articulo->fresh()->stock_reservado)->toBe('2.0000')->and($this->bebida->articulo->fresh()->stock_actual)->toBe('12.0000');
    $cajero = User::factory()->create();
    $cajero->assignRole('cajero');
    $turno = app(GestionCajas::class)->abrir($this->actor, Caja::skip(1)->first()->id, $cajero->id, '0', '', (string) Str::uuid());
    $cobro = cobroBeneficio($cajero, $pedido, $turno, [$raiz->id => 2], '0');
    expect($cobro->total)->toBe('0.00')->and($cobro->descuento_productos)->toBe('12.00')->and($cobro->detalles->first()->es_cortesia)->toBeTrue()->and($cobro->pagos->count())->toBe(0)->and(MovimientoCaja::count())->toBe(0);
    app(GestionEntregas::class)->entregar($this->actor, $pedido->id, $raiz->id, '2', GestionPedidos::huella($pedido->fresh()));
    expect($this->bebida->articulo->fresh()->stock_actual)->toBe('10.0000')->and($this->bebida->articulo->fresh()->stock_reservado)->toBe('0.0000');
});

test('cancellation removes only the unpaid share of a discount without rewriting an earlier collection', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 3]);
    $raiz = $pedido->detalles()->first();
    autorizarBeneficio($this->actor, $pedido, $raiz->id, datosBeneficio($this->motivo->id));
    $primero = cobroBeneficio($this->actor, $pedido, $this->turno, [$raiz->id => 1], '19.67');
    anularBeneficio($this->actor, $pedido, $raiz->id, '1');
    expect($raiz->fresh()->descuento_importe)->toBe('0.66')->and($primero->fresh()->total)->toBe('19.67');
    cobroBeneficio($this->actor, $pedido, $this->turno, [$raiz->id => 1], '19.67');
    expect(CobroDetalle::sum('descuento_producto'))->toEqual(0.66)->and(Cobro::sum('total'))->toEqual(39.34);
});

test('benefit application removal and retries retain history and require permission for the previous policy', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->bebida->id => 1]);
    $raiz = $pedido->detalles()->first();
    $datos = datosBeneficio($this->motivo->id, ['accion' => 'cortesia']);
    $s = app(GestionBeneficios::class);
    $plan = $s->previsualizar($this->actor, $pedido->id, $raiz->id, $datos);
    $clave = (string) Str::uuid();
    $r = $s->aplicar($this->actor, $pedido->id, $raiz->id, $clave, $plan['huella'], $datos);
    expect($s->aplicar($this->actor, $pedido->id, $raiz->id, $clave, $plan['huella'], $datos)->id)->toBe($r->id)->and(BeneficioPedido::count())->toBe(1);
    $delegado = User::factory()->create();
    $delegado->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'descuentos.aplicar']);
    expect(fn () => autorizarBeneficio($delegado, $pedido, $raiz->id, datosBeneficio($this->motivo->id)))->toThrow(AuthorizationException::class);
    autorizarBeneficio($this->actor, $pedido, $raiz->id, datosBeneficio($this->motivo->id, ['accion' => 'quitar']));
    expect($raiz->fresh()->es_cortesia)->toBeFalse()->and($raiz->fresh()->descuento_importe)->toBe('0.00')->and(BeneficioPedido::count())->toBe(2);
});

test('paid products inactive motives incompatible motives and excessive discounts cannot be modified', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 2]);
    $raiz = $pedido->detalles()->first();
    foreach ([['tipo' => 'porcentaje', 'valor' => '100.01'], ['tipo' => 'soles', 'valor' => '40.01'], ['valor' => '0']] as $cambio) {
        expect(fn () => autorizarBeneficio($this->actor, $pedido, $raiz->id, datosBeneficio($this->motivo->id, $cambio)))->toThrow(ValidationException::class);
    }
    $this->motivo->update(['activo' => false]);
    expect(fn () => autorizarBeneficio($this->actor, $pedido, $raiz->id, datosBeneficio($this->motivo->id)))->toThrow(ValidationException::class);
    $this->motivo->update(['activo' => true, 'tipo' => 'cortesia']);
    expect(fn () => autorizarBeneficio($this->actor, $pedido, $raiz->id, datosBeneficio($this->motivo->id)))->toThrow(ValidationException::class);
    $this->motivo->update(['tipo' => 'ambos']);
    cobroBeneficio($this->actor, $pedido, $this->turno, [$raiz->id => 1], '20');
    expect(fn () => autorizarBeneficio($this->actor, $pedido, $raiz->id, datosBeneficio($this->motivo->id)))->toThrow(ValidationException::class);
});

test('general discount excludes delivery and allocates exactly across selected lines', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 2, $this->bebida->id => 1], envio: '5');
    $lineas = $pedido->detalles()->whereNull('detalle_padre_id')->get();
    $seleccion = [];
    foreach ($lineas as $d) {
        $seleccion[$d->id] = (string) (int) $d->cantidad;
    }
    $cobro = cobroBeneficio($this->actor, $pedido, $this->turno, $seleccion, '46.40', ['incluirEnvio' => true, 'descuentoTipo' => 'porcentaje', 'descuentoValor' => '10', 'motivoId' => (string) $this->motivo->id, 'motivoDetalle' => 'Promoción autorizada del grupo']);
    expect($cobro->subtotal)->toBe('46.00')->and($cobro->descuento_general)->toBe('4.60')->and($cobro->cargo_envio)->toBe('5.00')->and($cobro->detalles->pluck('descuento_general')->all())->toBe(['4.00', '0.60'])->and($cobro->beneficio_motivo_nombre)->toBe('Cumpleaños');
});

test('general and product benefits cannot stack and unauthorized users cannot apply general discounts', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 1]);
    $raiz = $pedido->detalles()->first();
    autorizarBeneficio($this->actor, $pedido, $raiz->id, datosBeneficio($this->motivo->id));
    $extra = ['descuentoTipo' => 'porcentaje', 'descuentoValor' => '10', 'motivoId' => (string) $this->motivo->id, 'motivoDetalle' => 'Descuento del grupo'];
    expect(fn () => cobroBeneficio($this->actor, $pedido, $this->turno, [$raiz->id => 1], '17', $extra))->toThrow(ValidationException::class);
    $cajero = User::factory()->create();
    $cajero->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'cobros.crear', 'cajas.ver_todas']);
    expect(fn () => cobroBeneficio($cajero, $pedido, $this->turno, [$raiz->id => 1], '17', $extra))->toThrow(AuthorizationException::class);
});

test('general fixed discount distributes every cent and never changes another group already paid', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 2, $this->bebida->id => 1, $this->combo->id => 1]);
    $raices = $pedido->detalles()->whereNull('detalle_padre_id')->get();
    $primero = cobroBeneficio($this->actor, $pedido, $this->turno, [$raices[0]->id => 1], '20');
    $cobro = cobroBeneficio($this->actor, $pedido, $this->turno, [$raices[0]->id => 1, $raices[1]->id => 1, $raices[2]->id => 1], '50.99', ['descuentoTipo' => 'soles', 'descuentoValor' => '0.01', 'motivoId' => (string) $this->motivo->id, 'motivoDetalle' => 'Ajuste autorizado de prueba']);
    expect($cobro->descuento_general)->toBe('0.01')->and($cobro->detalles()->sum('descuento_general'))->toEqual(0.01)->and($primero->fresh()->total)->toBe('20.00');
});

test('Livewire courtesy and cancellation preserve the reviewed payload and lock target identities', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->bebida->id => 2]);
    $raiz = $pedido->detalles()->first();
    $pantalla = Livewire::test(AccionesProducto::class, ['pedidoId' => $pedido->id]);
    $pantalla->call('openBenefit', $raiz->id)->set('accion', 'cortesia')->set('motivoId', (string) $this->motivo->id)->set('motivo', 'Cumpleaños autorizado del cliente')->call('review')->assertDispatched('app:confirm')->set('accion', 'quitar')->call('confirmOperation')->assertRedirect(route('pos.pedido', $pedido));
    expect($raiz->fresh()->es_cortesia)->toBeTrue();
    Livewire::test(AccionesProducto::class, ['pedidoId' => $pedido->id])->call('openCancel', $raiz->id)->set('cantidad', '1')->set('motivo', 'Cliente retiró una unidad')->call('review')->set('cantidad', '2')->call('confirmOperation');
    expect($raiz->fresh()->cantidad_anulada)->toBe('1.0000');
    expect(fn () => Livewire::test(AccionesProducto::class, ['pedidoId' => $pedido->id])->set('detalleId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('courtesy checkout accepts an empty UI payment row and keeps all reviewed general fields immutable', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->bebida->id => 1]);
    $raiz = $pedido->detalles()->first();
    autorizarBeneficio($this->actor, $pedido, $raiz->id, datosBeneficio($this->motivo->id, ['accion' => 'cortesia']));
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido->fresh()])->call('review')->assertDispatched('app:confirm')->call('confirmCharge');
    expect(Cobro::first()->total)->toBe('0.00')->and(MovimientoCaja::count())->toBe(0);
});

test('parent operations require saving draft changes and methods cannot be used by a waiter without action permissions', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 1]);
    $id = $pedido->detalles()->first()->id;
    Livewire::test(PedidoPantalla::class, ['pedido' => $pedido])->set('observaciones', 'Sin guardar')->call('openCancel', $id)->assertHasErrors('seguridad');
    $mozo = User::factory()->create();
    $mozo->assignRole('mozo');
    $pedido->update(['mozo_id' => $mozo->id]);
    $this->actingAs($mozo);
    Livewire::test(AccionesProducto::class, ['pedidoId' => $pedido->id])->call('openCancel', $id)->assertForbidden();
});

test('full discounts cannot bypass the independent courtesy authorization including a general discount', function () {
    $pedido = pedidoBeneficio($this->actor, $this->turno, [$this->plato->id => 1]);
    $raiz = $pedido->detalles()->first();
    $delegado = User::factory()->create();
    $delegado->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'descuentos.aplicar', 'cobros.crear', 'cajas.ver_todas']);
    expect(fn () => autorizarBeneficio($delegado, $pedido, $raiz->id, datosBeneficio($this->motivo->id, ['valor' => '20'])))->toThrow(AuthorizationException::class);
    expect(fn () => cobroBeneficio($delegado, $pedido, $this->turno, [$raiz->id => 1], '0', ['descuentoTipo' => 'porcentaje', 'descuentoValor' => '100', 'motivoId' => (string) $this->motivo->id, 'motivoDetalle' => 'Descuento total de prueba']))->toThrow(AuthorizationException::class);
    $delegado->givePermissionTo('cortesias.aplicar');
    autorizarBeneficio($delegado, $pedido, $raiz->id, datosBeneficio($this->motivo->id, ['valor' => '20']));
    expect($raiz->fresh()->descuento_importe)->toBe('20.00')->and(Cobro::count())->toBe(0);
});
