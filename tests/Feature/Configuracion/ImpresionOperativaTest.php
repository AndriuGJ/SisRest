<?php

use App\Exceptions\FalloImpresion;
use App\Livewire\Configuracion\Impresoras;
use App\Livewire\Impresion\DocumentosImpresion;
use App\Models\AreaPreparacion;
use App\Models\Articulo;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\Empresa;
use App\Models\EnvioCocina;
use App\Models\Impresora;
use App\Models\MedioPago;
use App\Models\Mesa;
use App\Models\MotivoBeneficio;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Salon;
use App\Models\TrabajoImpresion;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Cajas\GestionCajas;
use App\Services\Cobros\GestionCobros;
use App\Services\Cocina\GestionCocina;
use App\Services\Configuracion\GestionImpresoras;
use App\Services\Devoluciones\GestionDevoluciones;
use App\Services\Devoluciones\ResolverProductosDevueltos;
use App\Services\Impresion\ImpresionOperativa;
use App\Services\Impresion\TicketOperativo;
use App\Services\Impresion\TicketRaster;
use App\Services\Impresion\TransporteEscPos;
use App\Services\Pos\ConfirmarPedido;
use App\Services\Pos\GestionAnulaciones;
use App\Services\Pos\GestionBeneficios;
use App\Services\Pos\GestionPedidos;
use Database\Seeders\EjemploInventarioSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    $this->cocinaPrinter = Impresora::where('codigo', 'cocina')->firstOrFail();
    $this->cajaPrinter = Impresora::where('codigo', 'caja_1')->firstOrFail();
    $this->cocinaPrinter->update(['host' => '127.0.0.1', 'puerto' => 19101, 'activo' => true]);
    $this->cajaPrinter->update(['host' => '127.0.0.1', 'puerto' => 19102, 'activo' => true]);
    $this->transporte = $this->mock(TransporteEscPos::class);
    Empresa::first()->update(['nombre_comercial' => 'Restaurante de prueba', 'direccion' => 'Av. Prueba 123', 'ruc' => '20123456789']);
});

function cuentaCobrosImpresion(User $actor, TurnoCaja $turno, array $productos, ?Mesa $mesa = null, ?string $envio = null): Pedido
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
function seleccionCobrosImpresion(Pedido $pedido, ?array $porProducto = null): array
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
function pagoCobrosImpresion(string $codigo, string $monto, string $entregado = '', ?string $referencia = null): array
{
    return ['medioId' => (string) MedioPago::where('codigo', $codigo)->firstOrFail()->id, 'monto' => $monto, 'entregado' => $entregado, 'referencia' => $referencia ?? (in_array($codigo, ['yape', 'plin'], true) ? 'OP-PRUEBA' : '')];
}
function datosCobrosImpresion(array $cambios = []): array
{
    return array_replace(['clienteId' => '', 'grupoPagador' => '', 'incluirEnvio' => false], $cambios);
}
function realizarCobroImpresion(User $actor, Pedido $pedido, TurnoCaja $turno, array $seleccion, array $pagos, array $datos = []): Cobro
{
    $datos = datosCobrosImpresion($datos);
    $plan = app(GestionCobros::class)->previsualizar($actor, $pedido->id, $turno->id, $datos, $seleccion, $pagos);

    return app(GestionCobros::class)->cobrar($actor, $pedido->id, $turno->id, (string) Str::uuid(), $plan['huella'], $datos, $seleccion, $pagos);
}

function solicitudImpresion(string $tipo, int $id, int $caja = 0, int $area = 0): array
{
    return compact('tipo', 'id', 'caja', 'area');
}
function imprimirOperativo(User $actor, array $solicitud, int $copia = 0, string $motivo = 'Copia autorizada por el administrador'): TrabajoImpresion
{
    $servicio = app(ImpresionOperativa::class);
    $plan = $servicio->previsualizar($actor, $solicitud, $copia);

    return $servicio->imprimir($actor, $solicitud, $copia, $motivo, (string) Str::uuid(), $plan['huella']);
}
function cuentaTicketImpresion(User $actor, TurnoCaja $turno, Producto $producto, string $q = '1', string $medio = 'efectivo', string $recibido = ''): Cobro
{
    $pedido = cuentaCobrosImpresion($actor, $turno, [$producto->id => $q]);
    $total = bcmul($q, $producto->precio_venta, 2);

    return realizarCobroImpresion($actor, $pedido, $turno, seleccionCobrosImpresion($pedido), $total === '0.00' ? [] : ['a' => pagoCobrosImpresion($medio, $total, $recibido)]);
}

test('operational command sends once outside its transaction with durable printer snapshot and no business changes', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 2, $this->gaseosa->id => 1]);
    $envio = EnvioCocina::first();
    $antes = $pedido->fresh()->toArray();
    $stock = Articulo::orderBy('id')->get()->toArray();
    $nivel = DB::transactionLevel();
    $this->transporte->shouldReceive('enviar')->once()->withArgs(function ($host, $puerto, $bytes) use ($nivel) {
        expect(DB::transactionLevel())->toBe($nivel)->and(TrabajoImpresion::first()->estado->value)->toBe('procesando')->and($host)->toBe('127.0.0.1')->and($puerto)->toBe(19101)->and($bytes)->toStartWith("\x1b@")->toContain('COMANDA')->toContain('2 x Chaufa')->toContain("\x1dV")->not->toContain('Gaseosa')->not->toContain('S/');

        return true;
    });
    $trabajo = imprimirOperativo($this->actor, solicitudImpresion('comanda', $envio->id));
    expect($trabajo->estado->value)->toBe('enviado')->and($trabajo->configuracion['puerto'])->toBe(19101)->and($trabajo->intentos)->toBe(1)->and($pedido->fresh()->toArray())->toBe($antes)->and(Articulo::orderBy('id')->get()->toArray())->toBe($stock)->and(MovimientoCaja::count())->toBe(0)->and(Cobro::count())->toBe(0);
});

test('same print UUID and different UUID for the same original do not send twice', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $s = solicitudImpresion('comanda', EnvioCocina::first()->id);
    $gestion = app(ImpresionOperativa::class);
    $plan = $gestion->previsualizar($this->actor, $s);
    $clave = (string) Str::uuid();
    $this->transporte->shouldReceive('enviar')->once();
    $uno = $gestion->imprimir($this->actor, $s, 0, '', $clave, $plan['huella']);
    $dos = $gestion->imprimir($this->actor, $s, 0, '', $clave, $plan['huella']);
    $tres = imprimirOperativo($this->actor, $s);
    expect($dos->id)->toBe($uno->id)->and($tres->id)->toBe($uno->id)->and(TrabajoImpresion::count())->toBe(1);
    $otro = User::factory()->create();
    $otro->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'impresion.comandas']);
    expect(imprimirOperativo($otro, $s)->id)->toBe($uno->id);
    expect(fn () => $gestion->imprimir($otro, $s, 0, '', $clave, $plan['huella']))->toThrow(HttpException::class);
});

test('each new kitchen lot prints only that lot and a combo uses its historical leaves and notes', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->combo->id => 1]);
    $envio = EnvioCocina::first();
    $envio->detalles()->update(['nota' => 'Sin cebolla, poco picante']);
    $this->transporte->shouldReceive('enviar')->twice();
    $uno = imprimirOperativo($this->actor, solicitudImpresion('comanda', $envio->id));
    $texto = app(ImpresionOperativa::class)->texto($uno);
    expect($texto)->toContain('Combo: Chaufa y gaseosa')->toContain('Sin cebolla')->not->toContain('S/');
    app(GestionPedidos::class)->guardar($this->actor, $pedido->id, $this->turno->id, GestionPedidos::huella($pedido->fresh()), ['clienteId' => '', 'comensales' => '', 'observaciones' => ''], ['n' => ['detalleId' => '', 'productoId' => (string) $this->chaufa->id, 'cantidad' => '2', 'nota' => 'Segundo lote']]);
    app(ConfirmarPedido::class)->confirmar($this->actor, $pedido->id, $this->turno->id, GestionPedidos::huella($pedido->fresh()), (string) Str::uuid());
    $dos = imprimirOperativo($this->actor, solicitudImpresion('comanda', EnvioCocina::latest('id')->first()->id));
    expect(app(ImpresionOperativa::class)->texto($dos))->toContain('LOTE 2')->toContain('2 x Chaufa')->toContain('Segundo lote')->not->toContain('Sin cebolla');
});

test('cancellation notice prints canceled quantities without applying cancellation a second time', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 3]);
    $detalle = $pedido->detalles()->first();
    $gestion = app(GestionAnulaciones::class);
    $plan = $gestion->previsualizar($this->actor, $pedido->id, $detalle->id, '1', 'Cliente canceló una unidad');
    $anulacion = $gestion->anular($this->actor, $pedido->id, $detalle->id, '1', 'Cliente canceló una unidad', (string) Str::uuid(), $plan['huella']);
    $this->transporte->shouldReceive('enviar')->twice();
    $stock = $this->chaufa->articulo->fresh()->toArray();
    $comanda = imprimirOperativo($this->actor, solicitudImpresion('comanda', EnvioCocina::first()->id));
    $aviso = imprimirOperativo($this->actor, solicitudImpresion('anulacion', $anulacion->id, 0, $detalle->area_preparacion_id));
    expect(app(ImpresionOperativa::class)->texto($comanda))->toContain('2 x Chaufa')->not->toContain('Ya anulados:')->and(app(ImpresionOperativa::class)->texto($aviso))->toContain('ANULACION DE COMANDA')->toContain('1 x Chaufa')->not->toContain('S/')->and($this->chaufa->articulo->fresh()->toArray())->toBe($stock);
    expect(fn () => imprimirOperativo($this->actor, solicitudImpresion('anulacion', $anulacion->id, 0, 999)))->toThrow(ValidationException::class);
});

test('paid pending cancellation has its own kitchen notice without exposing refund payments or costs', function () {
    $cobro = cuentaTicketImpresion($this->actor, $this->turno, $this->chaufa);
    $d = $cobro->detalles()->first();
    $p = $cobro->pagos()->first();
    $s = ['a' => ['detalleId' => (string) $d->id, 'cantidad' => '1']];
    $pag = ['a' => ['pagoId' => (string) $p->id, 'monto' => '20', 'referencia' => '']];
    $g = app(GestionDevoluciones::class);
    $plan = $g->previsualizar($this->actor, $cobro->id, $this->turno->id, $s, $pag, false, 'Cliente cancela el plato');
    $dev = $g->devolver($this->actor, $cobro->id, $this->turno->id, $s, $pag, false, 'Cliente cancela el plato', (string) Str::uuid(), $plan['huella']);
    $dd = $dev->detalles()->first();
    $r = app(ResolverProductosDevueltos::class);
    $plan = $r->previsualizar($this->actor, $dd->id, '1', 'cancelar_pendiente', [], 'Cancelar entrega no iniciada');
    $res = $r->resolver($this->actor, $dd->id, '1', 'cancelar_pendiente', [], 'Cancelar entrega no iniciada', (string) Str::uuid(), $plan['huella']);
    $mozo = User::factory()->create();
    $mozo->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'impresion.comandas']);
    $this->transporte->shouldReceive('enviar')->once();
    $trabajo = imprimirOperativo($mozo, solicitudImpresion('cancelacion_pagada', $res->id, 0, $cobro->pedido->detalles()->first()->area_preparacion_id));
    expect(app(ImpresionOperativa::class)->texto($trabajo))->toContain('1 x Chaufa')->not->toContain('S/')->and(MovimientoCaja::where('tipo', 'egreso')->count())->toBe(1);
});

test('prebill excludes paid quantities and paid shipping including refunds and prints remaining benefits precisely', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->gaseosa->id => 3], envio: '5');
    realizarCobroImpresion($this->actor, $pedido, $this->turno, seleccionCobrosImpresion($pedido, [$this->gaseosa->id => 1]), ['a' => pagoCobrosImpresion('yape', '11')], ['incluirEnvio' => true]);
    $this->transporte->shouldReceive('enviar')->once();
    $job = imprimirOperativo($this->actor, solicitudImpresion('precuenta', $pedido->id, $this->turno->caja_id));
    expect($job->contenido['lineas'][0]['cantidad'])->toBe('2')->and($job->contenido['totales'][2]['monto'])->toBe('0.00')->and($job->contenido['totales'][3]['monto'])->toBe('12.00')->and($this->gaseosa->articulo->fresh()->stock_reservado)->toBe('3.0000')->and($this->turno->efectivoEsperado())->toBe('100.00');
});

test('prebill records a new version only when its content changes and old copies stay historical', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->gaseosa->id => 2]);
    $s = solicitudImpresion('precuenta', $pedido->id, $this->turno->caja_id);
    $this->transporte->shouldReceive('enviar')->times(3);
    $uno = imprimirOperativo($this->actor, $s);
    expect(imprimirOperativo($this->actor, $s)->id)->toBe($uno->id);
    realizarCobroImpresion($this->actor, $pedido, $this->turno, seleccionCobrosImpresion($pedido, [$this->gaseosa->id => 1]), ['a' => pagoCobrosImpresion('yape', '6')]);
    $dos = imprimirOperativo($this->actor, $s);
    $copia = imprimirOperativo($this->actor, $s, $uno->id);
    expect($dos->id)->not->toBe($uno->id)->and($dos->contenido['totales'][3]['monto'])->toBe('6.00')->and($copia->contenido)->toBe($uno->contenido)->and($copia->contenido['totales'][3]['monto'])->toBe('12.00')->and(app(ImpresionOperativa::class)->texto($copia))->toContain('COPIA / REIMPRESION');
});

test('draft products block new prebills but do not block a historical copy', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    $s = solicitudImpresion('precuenta', $pedido->id, $this->turno->caja_id);
    $this->transporte->shouldReceive('enviar')->twice();
    $uno = imprimirOperativo($this->actor, $s);
    app(GestionPedidos::class)->guardar($this->actor, $pedido->id, $this->turno->id, GestionPedidos::huella($pedido->fresh()), ['clienteId' => '', 'comensales' => '', 'observaciones' => ''], ['n' => ['detalleId' => '', 'productoId' => (string) $this->chaufa->id, 'cantidad' => '1', 'nota' => '']]);
    expect(fn () => imprimirOperativo($this->actor, $s))->toThrow(ValidationException::class);
    expect(imprimirOperativo($this->actor, $s, $uno->id)->contenido)->toBe($uno->contenido);
});

test('ticket prints original combined payments applied and change without touching the receipt or stock', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->gaseosa->id => 2]);
    $cobro = realizarCobroImpresion($this->actor, $pedido, $this->turno, seleccionCobrosImpresion($pedido), ['a' => pagoCobrosImpresion('efectivo', '5', '20'), 'b' => pagoCobrosImpresion('yape', '7')]);
    $this->transporte->shouldReceive('enviar')->once();
    $antes = $cobro->fresh()->toArray();
    $cash = $this->turno->efectivoEsperado();
    $job = imprimirOperativo($this->actor, solicitudImpresion('ticket', $cobro->id, $this->turno->caja_id));
    $texto = app(ImpresionOperativa::class)->texto($job);
    expect($texto)->toContain('TICKET DE COBRO')->toContain('NO ES BOLETA NI FACTURA')->toContain('vuelto S/ 15.00')->toContain('Yape')->and($job->contenido['pagos'][0]['monto'])->toBe('5.00')->and($job->contenido['pagos'][1]['monto'])->toBe('7.00')->and($cobro->fresh()->toArray())->toBe($antes)->and($this->turno->efectivoEsperado())->toBe($cash)->and(MovimientoInventario::count())->toBe(0);
});

test('receipt copy preserves company client and payment snapshots despite later configuration changes', function () {
    $cobro = cuentaTicketImpresion($this->actor, $this->turno, $this->gaseosa);
    $this->transporte->shouldReceive('enviar')->twice();
    $s = solicitudImpresion('ticket', $cobro->id, $this->turno->caja_id);
    $uno = imprimirOperativo($this->actor, $s);
    Empresa::first()->update(['nombre_comercial' => 'Nombre cambiado']);
    MedioPago::where('codigo', 'efectivo')->update(['nombre' => 'Cambio de medio']);
    $this->cajaPrinter->update(['puerto' => 19109, 'ancho_mm' => 58]);
    $dos = imprimirOperativo($this->actor, $s, $uno->id);
    expect($dos->contenido)->toBe($uno->contenido)->and($dos->configuracion['puerto'])->toBe(19109)->and($dos->configuracion['ancho_mm'])->toBe(58)->and($uno->fresh()->configuracion['puerto'])->toBe(19102)->and(app(ImpresionOperativa::class)->texto($dos))->not->toContain('Nombre cambiado')->not->toContain('Cambio de medio');
});

test('zero total tickets have no fictitious payment and may print after the cash shift closes', function () {
    $this->gaseosa->update(['precio_venta' => '0']);
    $cobro = cuentaTicketImpresion($this->actor, $this->turno, $this->gaseosa);
    $this->turno->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    $this->transporte->shouldReceive('enviar')->once();
    $job = imprimirOperativo($this->actor, solicitudImpresion('ticket', $cobro->id, $this->turno->caja_id));
    expect($job->contenido['pagos'])->toBe([])->and($job->contenido['totales'][4]['monto'])->toBe('0.00')->and(MovimientoCaja::count())->toBe(0);
});

test('reprints require motive separate permission and keep their own unique UUID', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $s = solicitudImpresion('comanda', EnvioCocina::first()->id);
    $this->transporte->shouldReceive('enviar')->twice();
    $uno = imprimirOperativo($this->actor, $s);
    $mozo = User::factory()->create();
    $mozo->givePermissionTo(['pos.ver', 'pedidos.ver_todos', 'impresion.comandas']);
    expect(fn () => imprimirOperativo($mozo, $s, $uno->id))->toThrow(AuthorizationException::class);
    expect(fn () => imprimirOperativo($this->actor, $s, $uno->id, ''))->toThrow(ValidationException::class);
    $g = app(ImpresionOperativa::class);
    $plan = $g->previsualizar($this->actor, $s, $uno->id);
    $clave = (string) Str::uuid();
    $copia = $g->imprimir($this->actor, $s, $uno->id, 'Papel dañado verificado', $clave, $plan['huella']);
    expect($g->imprimir($this->actor, $s, $uno->id, 'Papel dañado verificado', $clave, $plan['huella'])->id)->toBe($copia->id)->and($copia->reimpresion_de_id)->toBe($uno->id)->and(TrabajoImpresion::count())->toBe(2);
});

test('changed preparation progress or printer settings invalidate review before any network send', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $envio = EnvioCocina::first();
    $s = solicitudImpresion('comanda', $envio->id);
    $g = app(ImpresionOperativa::class);
    $plan = $g->previsualizar($this->actor, $s);
    $linea = $envio->detalles()->first();
    app(GestionCocina::class)->avanzar($this->actor, $linea->id, 'iniciar', '1', GestionCocina::huella($linea));
    $this->transporte->shouldNotReceive('enviar');
    expect(fn () => $g->imprimir($this->actor, $s, 0, '', (string) Str::uuid(), $plan['huella']))->toThrow(ValidationException::class);
    $plan = $g->previsualizar($this->actor, $s);
    $this->cocinaPrinter->update(['puerto' => 19108]);
    expect(fn () => $g->imprimir($this->actor, $s, 0, '', (string) Str::uuid(), $plan['huella']))->toThrow(ValidationException::class);
    expect(TrabajoImpresion::count())->toBe(0);
});

test('disabled missing or conflicting destination printers do not modify confirmed orders or payments', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $s = solicitudImpresion('comanda', EnvioCocina::first()->id);
    $this->cocinaPrinter->update(['activo' => false]);
    $this->transporte->shouldNotReceive('enviar');
    expect(fn () => imprimirOperativo($this->actor, $s))->toThrow(ValidationException::class);
    $this->cocinaPrinter->update(['activo' => true]);
    Impresora::create(['codigo' => 'duplicada', 'nombre' => 'Duplicada', 'host' => '127.0.0.1', 'puerto' => 19104, 'activo' => true, 'area_preparacion_id' => $this->cocinaPrinter->area_preparacion_id]);
    expect(fn () => imprimirOperativo($this->actor, $s))->toThrow(ValidationException::class);
    expect($pedido->detalles()->first()->estado->value)->toBe('confirmado')->and($this->chaufa->articulo->fresh()->stock_reservado)->toBe('1.0000')->and(TrabajoImpresion::count())->toBe(0);
});

test('network errors and partial writes remain distinct and original requests never retry', function (bool $incierto, string $estado) {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $s = solicitudImpresion('comanda', EnvioCocina::first()->id);
    $this->transporte->shouldReceive('enviar')->once()->andThrow(new FalloImpresion('Resultado simulado', $incierto));
    $uno = imprimirOperativo($this->actor, $s);
    expect($uno->estado->value)->toBe($estado)->and(imprimirOperativo($this->actor, $s)->id)->toBe($uno->id)->and(TrabajoImpresion::count())->toBe(1)->and($pedido->detalles()->first()->cantidad_entregada)->toBe('0.0000');
})->with([[false, 'error'], [true, 'resultado_incierto']]);

test('in-flight jobs block printer edits and a different document while interrupted jobs become uncertain', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $s = solicitudImpresion('comanda', EnvioCocina::first()->id);
    $g = app(ImpresionOperativa::class);
    $plan = $g->previsualizar($this->actor, $s);
    $job = TrabajoImpresion::create(['pedido_id' => $pedido->id, 'impresora_id' => $this->cocinaPrinter->id, 'usuario_id' => $this->actor->id, 'tipo' => 'comanda', 'documento_type' => EnvioCocina::class, 'documento_id' => $s['id'], 'contenido' => $plan['contenido'], 'solicitud' => $s, 'configuracion' => $plan['configuracion'], 'estado' => 'procesando', 'clave_idempotencia' => (string) Str::uuid(), 'clave_documento' => 'comanda:'.$s['id'].':'.$this->cocinaPrinter->area_preparacion_id]);
    expect(fn () => app(GestionImpresoras::class)->cambiarEstado($this->actor, $this->cocinaPrinter->id, false))->toThrow(ValidationException::class);
    expect(fn () => imprimirOperativo($this->actor, $s, $job->id))->toThrow(ValidationException::class);
    $this->transporte->shouldNotReceive('enviar');
    TrabajoImpresion::whereKey($job->id)->update(['updated_at' => now()->subMinutes(2)]);
    $g->actualizar($this->actor, $pedido->id);
    expect($job->fresh()->estado->value)->toBe('resultado_incierto')->and(imprimirOperativo($this->actor, $s)->id)->toBe($job->id);
    app(GestionImpresoras::class)->cambiarEstado($this->actor, $this->cocinaPrinter->id, false);
    expect($this->cocinaPrinter->fresh()->activo)->toBeFalse();
});

test('order and cash scope plus inactive or revoked users protect printing services and routes', function () {
    $cobro = cuentaTicketImpresion($this->actor, $this->turno, $this->gaseosa);
    $s = solicitudImpresion('ticket', $cobro->id, $this->turno->caja_id);
    $mozo = User::factory()->create();
    $mozo->assignRole('mozo');
    $this->actingAs($mozo)->get(route('pos.imprimir-cobro', $cobro))->assertForbidden();
    expect(fn () => imprimirOperativo($mozo, $s))->toThrow(AuthorizationException::class);
    $cajero = User::factory()->create();
    $cajero->assignRole('cajero');
    expect(fn () => imprimirOperativo($cajero, $s))->toThrow(HttpException::class);
    $propio = User::factory()->create();
    $propio->givePermissionTo(['pos.ver', 'pedidos.ver_propios', 'impresion.precuentas']);
    expect(fn () => imprimirOperativo($propio, solicitudImpresion('precuenta', $cobro->pedido_id, $this->turno->caja_id)))->toThrow(HttpException::class);
    $this->actor->update(['activo' => false]);
    expect(fn () => imprimirOperativo($this->actor, $s))->toThrow(HttpException::class);
});

test('Livewire preview is read-only until confirmed with SweetAlert and locks its reviewed identity', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $envio = EnvioCocina::first();
    $this->transporte->shouldReceive('enviar')->once();
    $ui = Livewire::test(DocumentosImpresion::class, ['pedido' => $pedido])->call('openPreview', 'comanda', $envio->id)->assertSet('showPreview', true)->assertSee('COMANDA');
    expect(TrabajoImpresion::count())->toBe(0);
    $ui->call('requestConfirmation')->assertDispatched('app:confirm');
    expect(TrabajoImpresion::count())->toBe(0);
    $ui->call('confirmPrint')->assertDispatched('app:alert')->assertSet('showPreview', false);
    expect(TrabajoImpresion::count())->toBe(1);
    expect(fn () => $ui->set('solicitud.id', 999))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => $ui->set('texto', 'Documento alterado'))->toThrow(CannotUpdateLockedPropertyException::class);
    $ui->call('confirmPrint');
    expect(TrabajoImpresion::count())->toBe(1);
});

test('Livewire copy preserves reviewed reason and shows recorded errors without changing a paid receipt', function () {
    $cobro = cuentaTicketImpresion($this->actor, $this->turno, $this->gaseosa);
    $this->transporte->shouldReceive('enviar')->once()->andThrow(new FalloImpresion('Sin conexión de prueba', false));
    $ui = Livewire::test(DocumentosImpresion::class, ['cobro' => $cobro])->call('openPreview', 'ticket', $cobro->id)->call('requestConfirmation')->call('confirmPrint')->assertDispatched('app:alert')->assertSee('Sin conexión de prueba');
    $this->transporte->shouldReceive('enviar')->once();
    $job = TrabajoImpresion::first();
    $ui->call('openCopy', $job->id)->set('motivoCopia', 'Copia revisada por falta de papel')->call('requestConfirmation')->assertDispatched('app:confirm')->set('motivoCopia', 'Cambio posterior al revisar')->call('confirmPrint');
    expect(TrabajoImpresion::latest('id')->first()->motivo_reimpresion)->toBe('Copia revisada por falta de papel')->and(Cobro::count())->toBe(1)->and(MovimientoCaja::count())->toBe(1);
});

test('printing pages are accessible from the order and receipt and a waiter sees no financial tickets', function () {
    $cobro = cuentaTicketImpresion($this->actor, $this->turno, $this->chaufa);
    $this->get(route('pos.imprimir-pedido', $cobro->pedido))->assertOk()->assertSee('Comandas por lote y destino');
    $this->get(route('pos.imprimir-cobro', $cobro))->assertOk()->assertSee('Vista previa del ticket');
    $this->get(route('pos.cobro', $cobro))->assertSee('Imprimir ticket');
    $mozo = User::factory()->create();
    $mozo->assignRole('mozo');
    $cobro->pedido->update(['mozo_id' => $mozo->id]);
    $this->actingAs($mozo)->get(route('pos.imprimir-pedido', $cobro->pedido))->assertOk()->assertDontSee('Vista previa del ticket');
});

test('preview and ESC POS output sanitize controls and keep each line within thermal paper width', function (int $ancho, int $columnas) {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $envio = EnvioCocina::first();
    $envio->detalles()->update(['descripcion' => "Plato ñ\x1b\x40".str_repeat('muy largo ', 12), 'nota' => "Sin\x1b\x70 cebolla\n<script>alert(1)</script>"]);
    $this->cocinaPrinter->update(['ancho_mm' => $ancho]);
    $this->transporte->shouldReceive('enviar')->once();
    $job = imprimirOperativo($this->actor, solicitudImpresion('comanda', $envio->id));
    $texto = app(ImpresionOperativa::class)->texto($job);
    foreach (explode("\n", $texto) as $linea) {
        expect(strlen($linea))->toBeLessThanOrEqual($columnas);
    }
    expect($texto)->not->toContain("\x1b")->not->toContain("\x00");
    Livewire::test(DocumentosImpresion::class, ['pedido' => $pedido])->call('viewJob', $job->id)->assertDontSee('<script>alert(1)</script>', false);
})->with([[58, 32], [80, 48]]);

test('ESC POS generation failure is durable and a new original request does not try the network', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $s = solicitudImpresion('comanda', EnvioCocina::first()->id);
    $this->mock(TicketOperativo::class)->shouldReceive('texto')->andReturn('Documento')->shouldReceive('generar')->once()->andThrow(new RuntimeException('Error de formato simulado'));
    $this->transporte->shouldNotReceive('enviar');
    $job = imprimirOperativo($this->actor, $s);
    expect($job->estado->value)->toBe('error')->and(imprimirOperativo($this->actor, $s)->id)->toBe($job->id);
});

test('kitchen and bar receive different combo leaves on their assigned printers', function () {
    $barra = AreaPreparacion::where('codigo', 'barra')->first();
    $barra->update(['activo' => true]);
    $this->gaseosa->update(['area_preparacion_id' => $barra->id]);
    Impresora::create(['codigo' => 'barra-prueba', 'nombre' => 'Barra prueba', 'host' => '127.0.0.1', 'puerto' => 19103, 'activo' => true, 'area_preparacion_id' => $barra->id]);
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->combo->id => 1]);
    $this->transporte->shouldReceive('enviar')->once()->withArgs(fn ($h, $p, $b) => $p === 19101 && str_contains($b, 'Chaufa (ejemplo)') && ! str_contains($b, '1 x Gaseosa'));
    $this->transporte->shouldReceive('enviar')->once()->withArgs(fn ($h, $p, $b) => $p === 19103 && str_contains($b, '1 x Gaseosa (ejemplo)') && ! str_contains($b, '1 x Chaufa (ejemplo)'));
    foreach (EnvioCocina::where('pedido_id', $pedido->id)->get() as $envio) {
        imprimirOperativo($this->actor, solicitudImpresion('comanda', $envio->id));
    }
    expect(TrabajoImpresion::count())->toBe(2)->and($pedido->detalles()->whereNull('detalle_padre_id')->first()->cantidad_cobrada)->toBe('0.0000');
});

test('prebill reflects the remaining saved product discount without consuming a new benefit', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->gaseosa->id => 3]);
    $detalle = $pedido->detalles()->first();
    $motivo = MotivoBeneficio::whereIn('tipo', ['descuento', 'ambos'])->first();
    $datos = ['accion' => 'descuento', 'tipo' => 'soles', 'valor' => '1', 'motivoId' => (string) $motivo->id, 'motivo' => 'Promoción para esta mesa'];
    $g = app(GestionBeneficios::class);
    $plan = $g->previsualizar($this->actor, $pedido->id, $detalle->id, $datos);
    $g->aplicar($this->actor, $pedido->id, $detalle->id, (string) Str::uuid(), $plan['huella'], $datos);
    realizarCobroImpresion($this->actor, $pedido, $this->turno, seleccionCobrosImpresion($pedido, [$this->gaseosa->id => 1]), ['a' => pagoCobrosImpresion('yape', '5.67')]);
    $antes = $detalle->fresh()->toArray();
    $this->transporte->shouldReceive('enviar')->once();
    $job = imprimirOperativo($this->actor, solicitudImpresion('precuenta', $pedido->id, $this->turno->caja_id));
    expect($job->contenido['lineas'][0]['descuento'])->toBe('0.67')->and($job->contenido['totales'][3]['monto'])->toBe('11.33')->and($detalle->fresh()->toArray())->toBe($antes);
});

test('a refund does not recreate prebill debt and a ticket preserves the original collection', function () {
    $cobro = cuentaTicketImpresion($this->actor, $this->turno, $this->gaseosa);
    $d = $cobro->detalles()->first();
    $p = $cobro->pagos()->first();
    $seleccion = ['a' => ['detalleId' => (string) $d->id, 'cantidad' => '1']];
    $pagos = ['a' => ['pagoId' => (string) $p->id, 'monto' => '6', 'referencia' => '']];
    $g = app(GestionDevoluciones::class);
    $plan = $g->previsualizar($this->actor, $cobro->id, $this->turno->id, $seleccion, $pagos, false, 'Cliente solicitó reintegro');
    $g->devolver($this->actor, $cobro->id, $this->turno->id, $seleccion, $pagos, false, 'Cliente solicitó reintegro', (string) Str::uuid(), $plan['huella']);
    $this->transporte->shouldReceive('enviar')->twice();
    $prec = imprimirOperativo($this->actor, solicitudImpresion('precuenta', $cobro->pedido_id, $this->turno->caja_id));
    $ticket = imprimirOperativo($this->actor, solicitudImpresion('ticket', $cobro->id, $this->turno->caja_id));
    expect($prec->contenido['totales'][3]['monto'])->toBe('0.00')->and($ticket->contenido['totales'][4]['monto'])->toBe('6.00')->and($ticket->contenido['pagos'][0]['monto'])->toBe('6.00');
});

test('the second authorized cash printer can print a ticket without receiving a new payment', function () {
    $cobro = cuentaTicketImpresion($this->actor, $this->turno, $this->gaseosa);
    $caja = Caja::where('id', '<>', $this->turno->caja_id)->first();
    $printer = $caja->impresora;
    $printer->update(['host' => '127.0.0.1', 'puerto' => 19104, 'activo' => true]);
    $this->transporte->shouldReceive('enviar')->once()->withArgs(fn ($h, $p, $b) => $p === 19104);
    $job = imprimirOperativo($this->actor, solicitudImpresion('ticket', $cobro->id, $caja->id));
    expect($job->impresora_id)->toBe($printer->id)->and($this->turno->efectivoEsperado())->toBe('106.00')->and(TurnoCaja::count())->toBe(1);
});

test('an actual TCP receiver gets the whole operational ticket and no extra connection on repeated requests', function () {
    $servidor = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($servidor)->not->toBeFalse();
    try {
        $direccion = stream_socket_get_name($servidor, false);
        $puerto = (int) substr(strrchr($direccion, ':'), 1);
        $this->cajaPrinter->update(['puerto' => $puerto]);
        $this->instance(TransporteEscPos::class, new TransporteEscPos);
        $cobro = cuentaTicketImpresion($this->actor, $this->turno, $this->gaseosa);
        $s = solicitudImpresion('ticket', $cobro->id, $this->turno->caja_id);
        $job = imprimirOperativo($this->actor, $s);
        $cliente = stream_socket_accept($servidor, 2);
        expect($cliente)->not->toBeFalse();
        try {
            $bytes = stream_get_contents($cliente);
            expect($bytes)->toStartWith("\x1b@")->toContain('TICKET DE COBRO')->toContain('TOTAL COBRADO ORIGINAL')->toContain("\x1dV");
        } finally {
            fclose($cliente);
        }
        expect(imprimirOperativo($this->actor, $s)->id)->toBe($job->id);
        stream_set_blocking($servidor, false);
        expect(@stream_socket_accept($servidor, 0))->toBeFalse();
    } finally {
        fclose($servidor);
    }
});

test('editing printer connection or paper preserves its existing cash-box assignment and allows a recovery copy', function () {
    $cobro = cuentaTicketImpresion($this->actor, $this->turno, $this->gaseosa);
    $solicitud = solicitudImpresion('ticket', $cobro->id, $this->turno->caja_id);
    $this->transporte->shouldReceive('enviar')->once()->andThrow(new FalloImpresion('No conectado', false));
    $original = imprimirOperativo($this->actor, $solicitud);
    Livewire::test(Impresoras::class)->call('editRecord', $this->cajaPrinter->id)->set('puerto', '19108')->set('ancho', '58')->call('save')->assertHasNoErrors();
    expect($this->turno->caja->fresh()->impresora_id)->toBe($this->cajaPrinter->id);
    $this->transporte->shouldReceive('enviar')->once()->withArgs(fn ($h, $p, $b) => $p === 19108);
    $copia = imprimirOperativo($this->actor, $solicitud, $original->id);
    expect($copia->estado->value)->toBe('enviado')->and($copia->configuracion['ancho_mm'])->toBe(58)->and($original->fresh()->configuracion['puerto'])->toBe(19102);
});

test('request identity compares typed JSON fields independently of object key order', function () {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 1]);
    $s = solicitudImpresion('comanda', EnvioCocina::first()->id);
    $g = app(ImpresionOperativa::class);
    $plan = $g->previsualizar($this->actor, $s);
    $clave = (string) Str::uuid();
    $this->transporte->shouldReceive('enviar')->once();
    $original = $g->imprimir($this->actor, $s, 0, '', $clave, $plan['huella']);
    $reordenada = ['area' => $s['area'], 'caja' => $s['caja'], 'id' => $s['id'], 'tipo' => $s['tipo']];
    expect($g->imprimir($this->actor, $reordenada, 0, '', $clave, $plan['huella'])->id)->toBe($original->id)->and(TrabajoImpresion::count())->toBe(1);
    $otraCaja = $reordenada;
    $otraCaja['caja'] = $this->turno->caja_id;
    expect(fn () => $g->imprimir($this->actor, $otraCaja, 0, '', $clave, $plan['huella']))->toThrow(HttpException::class);
});

test('graphical tickets generate ESC POS bytes with each configured font and paper width', function (string $fuente, int $ancho) {
    $bytes = app(TicketOperativo::class)->generar("PRECUENTA\nProducto    S/ 6.00\n", null, null, ['fuente' => $fuente, 'tamano' => 12, 'grafica' => true], $ancho);
    expect(strlen($bytes))->toBeGreaterThan(100)->and(str_contains($bytes, "\x1dv0"))->toBeTrue();
})->with(['poppins', 'inter', 'roboto'])->with([58, 80]);

test('prebill with branding generates and sends the document instead of failing before transport', function (bool $logo, bool $grafica) {
    Storage::fake('local');
    if ($logo) {
        Storage::disk('local')->put('marca/prueba.png', UploadedFile::fake()->image('logo.png', 120, 60)->get());
    }
    Empresa::firstOrFail()->update(['logo_ruta' => $logo ? 'marca/prueba.png' : null, 'apariencia' => ['fuente' => 'poppins', 'grafica' => $grafica]]);
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->gaseosa->id => 1]);
    $solicitud = solicitudImpresion('precuenta', $pedido->id, $this->turno->caja_id);
    $impresion = app(ImpresionOperativa::class);
    $plan = $impresion->previsualizar($this->actor, $solicitud);
    $this->transporte->shouldReceive('enviar')->once()->with('127.0.0.1', 19102, Mockery::on(fn ($bytes) => str_contains($bytes, "\x1dv0") && strlen($bytes) > 100));
    $trabajo = $impresion->imprimir($this->actor, $solicitud, 0, '', (string) Str::uuid(), $plan['huella']);
    expect($trabajo->estado->value)->toBe('enviado')->and($trabajo->ultimo_error)->toBeNull()
        ->and($pedido->fresh()->estado->value)->not->toBe('cerrado')->and(Cobro::count())->toBe(0);
})->with([[true, false], [false, true], [true, true]]);

test('kitchen command prints only its concise header and consumption without company branding or operational footer', function (int $ancho) {
    Empresa::firstOrFail()->update(['logo_ruta' => 'marca/logo.png', 'telefono' => '999888777']);
    $mesa = Mesa::create(['salon_id' => Salon::firstOrFail()->id, 'codigo' => 'MESA 1']);
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->chaufa->id => 1], $mesa);
    $envio = EnvioCocina::firstOrFail();
    $envio->detalles()->update(['nota' => 'Sin cebolla']);
    $this->cocinaPrinter->update(['ancho_mm' => $ancho]);
    $this->transporte->shouldReceive('enviar')->once()->withArgs(fn ($host, $port, $bytes) => str_contains($bytes, 'COMANDA') && ! str_contains($bytes, 'Restaurante de prueba'));
    $job = imprimirOperativo($this->actor, solicitudImpresion('comanda', $envio->id));
    $texto = app(ImpresionOperativa::class)->texto($job);
    expect($job->contenido['logo'])->toBeNull()->and($job->contenido['empresa'])->toBe([])
        ->and($texto)->toContain('COMANDA', $pedido->codigo, 'LOTE 1', 'FECHA: '.$envio->enviado_at->timezone('America/Lima')->format('d/m/Y H:i'), 'MESA: MESA 1', 'PLATOS E INSUMOS', '1 x Chaufa', 'NOTA: Sin cebolla')
        ->not->toContain('Restaurante de prueba', 'RUC:', 'Av. Prueba', 'Tel:', 'Mozo:', 'Comensales:', 'Destino:', 'Sin iniciar:', 'Listos:', 'Origen:', 'Generado:', 'Impresion:', 'Por:', 'Verificar lote');
    $copia = app(TicketOperativo::class)->texto($job->contenido, $ancho, true);
    expect($copia)->toContain('COPIA / REIMPRESION', 'NO DUPLICAR PREPARACION', 'Sin cebolla')->not->toContain('Restaurante de prueba');
})->with([58, 80]);

test('compact prebill centers its company heading and prints aligned consumption without inventing tax or a fiscal QR', function (int $ancho, int $columnas) {
    $pedido = cuentaCobrosImpresion($this->actor, $this->turno, [$this->gaseosa->id => 2], envio: '5');
    $this->cajaPrinter->update(['ancho_mm' => $ancho]);
    $this->transporte->shouldReceive('enviar')->once();
    $job = imprimirOperativo($this->actor, solicitudImpresion('precuenta', $pedido->id, $this->turno->caja_id));
    $texto = app(ImpresionOperativa::class)->texto($job);
    $lineas = explode("\n", $texto);
    expect($lineas[0])->toBe(str_pad('Restaurante de prueba', $columnas, ' ', STR_PAD_BOTH))
        ->and($texto)->toContain('PRECUENTA', 'CLIENTE: CLIENTES VARIOS', 'DETALLE DE CONSUMO', 'P.U.', 'IMP.', '6.00', '12.00', 'DELIVERY', '17.00', 'DIECISIETE CON 00/100 SOLES')
        ->not->toContain('IGV', 'Precio unitario:', 'Generado:', 'Por:')
        ->and(array_key_exists('qr', $job->contenido))->toBeFalse();
    foreach ($lineas as $linea) {
        expect(strlen($linea))->toBeLessThanOrEqual($columnas);
    }
    expect($job->contenido['totales'][3]['monto'])->toBe('17.00')->and(Cobro::count())->toBe(0);
})->with([[58, 32], [80, 48]]);

test('graphical printing centers padded heading by its pixel width', function () {
    $ruta = app(TicketRaster::class)->texto(str_pad('RUC: 20123456789', 48, ' ', STR_PAD_BOTH)."\n", ['fuente' => 'poppins', 'tamano' => 12, 'grafica' => true], 80);
    $imagen = imagecreatefrompng($ruta);
    try {
        $minimo = imagesx($imagen);
        $maximo = 0;
        for ($y = 0; $y < imagesy($imagen); $y++) {
            for ($x = 0; $x < imagesx($imagen); $x++) {
                if ((imagecolorat($imagen, $x, $y) & 0xFF) < 128) {
                    $minimo = min($minimo, $x);
                    $maximo = max($maximo, $x);
                }
            }
        }
        expect($maximo)->toBeGreaterThan($minimo)->and(abs(($minimo + $maximo) / 2 - imagesx($imagen) / 2))->toBeLessThan(4);
    } finally {
        imagedestroy($imagen);
        unlink($ruta);
    }
});
