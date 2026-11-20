<?php

use App\Exceptions\FalloImpresion;
use App\Livewire\Cobros\CobroFormulario;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\MedioPago;
use App\Models\Mesa;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\ProcesoCobro;
use App\Models\Producto;
use App\Models\Salon;
use App\Models\SerieComprobante;
use App\Models\TrabajoImpresion;
use App\Models\User;
use App\Services\Cajas\GestionCajas;
use App\Services\Cobros\ProcesarVenta;
use App\Services\Facturacion\EmitirComprobante;
use App\Services\Impresion\TransporteEscPos;
use App\Services\Pos\ConfirmarPedido;
use App\Services\Pos\GestionPedidos;
use Database\Seeders\EjemploInventarioSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

function certificadoCheckout(): string
{
    static $pfx;
    if ($pfx) {
        return $pfx;
    }
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'Checkout TEST ONLY'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 2);
    openssl_pkcs12_export($cert, $pfx, $key, 'checkout-test');

    return $pfx;
}

beforeEach(function () {
    $this->seed([RolesPermisosSeeder::class, EjemploInventarioSeeder::class]);
    Storage::fake('local');
    $this->actor = User::factory()->create();
    $this->actor->assignRole('administrador');
    $this->actingAs($this->actor);
    $caja = Caja::firstOrFail();
    $caja->impresora->update(['host' => '127.0.0.1', 'puerto' => 19102, 'activo' => true]);
    $this->turno = app(GestionCajas::class)->abrir($this->actor, $caja->id, $this->actor->id, '100', '', (string) Str::uuid());
    $this->producto = Producto::where('codigo', 'EJ-GASEOSA')->firstOrFail();
    $this->producto->articulo->update(['stock_actual' => '200']);
    $this->mesa = Mesa::create(['salon_id' => Salon::firstOrFail()->id, 'codigo' => 'CHECKOUT']);
    $this->transporte = $this->spy(TransporteEscPos::class);
    Empresa::firstOrFail()->update(['ruc' => '20131312955', 'razon_social' => 'Restaurante Prueba', 'direccion' => 'Av. Prueba 100', 'ubigeo' => '150101', 'departamento' => 'LIMA', 'provincia' => 'LIMA', 'distrito' => 'LIMA']);
    Storage::disk('local')->put('certificados/checkout.enc', Crypt::encryptString(certificadoCheckout()));
    ConfiguracionFacturacion::firstOrFail()->update(['habilitada' => true, 'entorno' => 'beta', 'usuario_sol' => 'MODDATOS', 'clave_sol' => 'moddatos', 'certificado_ruta' => 'certificados/checkout.enc', 'clave_certificado' => 'checkout-test', 'tasa_impuesto' => '18', 'vigente_desde' => now('America/Lima')->toDateString()]);
    SerieComprobante::whereIn('serie', ['F001', 'B001'])->update(['activo' => true]);
});

function pedidoCheckout($test, int $cantidad = 1): Pedido
{
    $pedido = app(GestionPedidos::class)->abrir($test->actor, $test->turno->id, (string) Str::uuid(), ['modalidad' => 'mesa', 'mesaId' => (string) $test->mesa->id, 'mozoId' => (string) $test->actor->id, 'comensales' => '2', 'observaciones' => '', 'destinatario' => '', 'telefono' => '', 'direccion' => '', 'referencia' => '', 'costoEnvio' => '0']);
    app(GestionPedidos::class)->guardar($test->actor, $pedido->id, $test->turno->id, GestionPedidos::huella($pedido->fresh()), ['clienteId' => '', 'comensales' => '2', 'observaciones' => ''], ['nuevo' => ['detalleId' => '', 'productoId' => (string) $test->producto->id, 'cantidad' => (string) $cantidad, 'nota' => 'Sin hielo']]);
    app(ConfirmarPedido::class)->confirmar($test->actor, $pedido->id, $test->turno->id, GestionPedidos::huella($pedido->fresh()), (string) Str::uuid());

    return $pedido->fresh();
}

test('checkout note prints on selected cash box and retains prepaid table without showing history as the next screen', function () {
    $pedido = pedidoCheckout($this);
    $ui = Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->call('review')->assertHasNoErrors()->assertSet('showChargeReview', true)->assertSee('Caja de cobro e impresión')->call('confirmCharge', false)->assertHasNoErrors();
    $cobro = Cobro::firstOrFail();
    $ui->assertRedirect(route('pos.cobrar', ['pedido' => $pedido->id, 'cobro' => $cobro->id]));
    $proceso = ProcesoCobro::firstOrFail();
    expect($proceso->finalizado_at)->not->toBeNull()->and($proceso->error)->toBeNull()->and($pedido->fresh()->estado->value)->not->toBe('cerrado')
        ->and($this->mesa->ocupacion()->exists())->toBeTrue()->and($this->producto->articulo->fresh()->stock_actual)->toBe('200.0000')
        ->and(TrabajoImpresion::firstOrFail()->contenido['titulo'])->toBe('NOTA DE VENTA')->and(Comprobante::count())->toBe(0);
    $this->transporte->shouldHaveReceived('enviar')->once();
    $this->get(route('pos.cobrar', ['pedido' => $pedido->id, 'cobro' => $cobro->id]))->assertOk()->assertSee('Cobro registrado')->assertSee('sin valor tributario');
});

test('checkout release confirms stock delivery and one print while retries never double the payment', function () {
    $pedido = pedidoCheckout($this);
    $ui = Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->call('review')->call('confirmCharge', true)->assertHasNoErrors();
    expect($pedido->fresh()->estado->value)->toBe('cerrado')->and($this->mesa->ocupacion()->exists())->toBeFalse()
        ->and($this->producto->articulo->fresh()->stock_actual)->toBe('199.0000');
    $ui->call('confirmCharge', true)->assertHasNoErrors();
    expect(Cobro::count())->toBe(1)->and(MovimientoCaja::count())->toBe(1)->and(MovimientoInventario::where('tipo', 'entrega')->count())->toBe(1)->and(TrabajoImpresion::count())->toBe(1);
    $this->transporte->shouldHaveReceived('enviar')->once();
    $this->get(route('ventas.cobros'))->assertOk()->assertSee(Cobro::firstOrFail()->codigo);
    $this->get(route('ventas.cobro', Cobro::firstOrFail()))->assertOk();
});

test('checkout emits and prints a signed boleta for anonymous client below and at the threshold', function (string $importe) {
    $this->producto->update(['precio_venta' => $importe]);
    $pedido = pedidoCheckout($this);
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->set('documento', '03')->call('review')->assertHasNoErrors()->call('confirmCharge', false)->assertHasNoErrors();
    $proceso = ProcesoCobro::firstOrFail();
    $c = Comprobante::firstOrFail();
    expect($proceso->error)->toBeNull()->and($c->receptor['tipo'])->toBe('0')->and($c->estado_sunat->value)->toBe('pendiente')
        ->and(Storage::disk('local')->get($c->xml_ruta))->toContain('<ds:Signature')->and(TrabajoImpresion::firstOrFail()->solicitud['tipo'])->toBe('comprobante');
})->with(['6.00', '700.00']);

test('checkout rejects an anonymous boleta above 700 before any cash or document is written', function () {
    $this->producto->update(['precio_venta' => '700.01']);
    $pedido = pedidoCheckout($this);
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->set('documento', '03')->call('review')->assertHasErrors('clienteId');
    expect(Cobro::count())->toBe(0)->and(Comprobante::count())->toBe(0)->and(TrabajoImpresion::count())->toBe(0);
});

test('checkout creates selects and invoices a RUC customer without creating a login user', function () {
    $pedido = pedidoCheckout($this);
    $ui = Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->set('documento', '01')->call('abrirCliente')->set('nuevoNumero', '20131312955')->set('nuevoNombre', 'Cliente SAC')->set('nuevoDireccion', 'Av. Cliente 123')->call('guardarCliente')->assertHasNoErrors();
    expect($ui->get('clienteId'))->toBe((string) Cliente::firstOrFail()->id)->and(User::count())->toBe(1);
    $ui->call('review')->assertHasNoErrors()->call('confirmCharge', false)->assertHasNoErrors();
    expect(Comprobante::firstOrFail()->serie->tipoComprobante->codigo)->toBe('01')->and(Comprobante::firstOrFail()->receptor['numero'])->toBe('20131312955')->and(ProcesoCobro::firstOrFail()->error)->toBeNull();
});

test('checkout rejects factura without RUC and quick creation rejects duplicate or invalid identity', function () {
    $pedido = pedidoCheckout($this);
    $ui = Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->set('documento', '01')->call('review')->assertHasErrors('clienteId')->call('abrirCliente')->set('nuevoNumero', '20131312956')->set('nuevoNombre', 'Incorrecto')->set('nuevoDireccion', 'Av. Prueba')->call('guardarCliente')->assertHasErrors('nuevoNumero');
    expect(Cliente::count())->toBe(0)->and(Cobro::count())->toBe(0);
    $ui->set('nuevoNumero', '20131312955')->call('guardarCliente')->assertHasNoErrors()->call('abrirCliente')->set('nuevoNumero', '20131312955')->set('nuevoNombre', 'Duplicado')->set('nuevoDireccion', 'Av. Prueba')->call('guardarCliente')->assertHasErrors();
    expect(Cliente::count())->toBe(1);
});

test('checkout cancellation and missing printer do not register any payment', function () {
    $pedido = pedidoCheckout($this);
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->call('review')->assertHasNoErrors()->set('showChargeReview', false);
    expect(Cobro::count())->toBe(0);
    $this->turno->caja->impresora->update(['activo' => false]);
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->call('review')->assertHasErrors('turnoId');
    expect(Cobro::count())->toBe(0)->and(TrabajoImpresion::count())->toBe(0);
});

test('checkout cannot release a partly paid table but can print and retain it', function () {
    $pedido = pedidoCheckout($this, 2);
    $detalle = $pedido->detalles()->firstOrFail();
    $medio = MedioPago::where('codigo', 'efectivo')->firstOrFail();
    $ui = Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->set('cantidades.'.$detalle->id, '1')->call('usarMedio', $medio->id)->call('review')->assertSet('puedeLiberar', false)->call('confirmCharge', true)->assertHasErrors();
    expect(Cobro::count())->toBe(0);
    $ui->call('review')->call('confirmCharge', false)->assertHasNoErrors();
    expect(Cobro::count())->toBe(1)->and($this->mesa->ocupacion()->exists())->toBeTrue()->and(TrabajoImpresion::count())->toBe(1);
});

test('checkout preserves successful charge and release when paper transport fails and never automatically resends', function () {
    $this->transporte->shouldReceive('enviar')->once()->andThrow(new FalloImpresion('Impresora desconectada', false));
    $pedido = pedidoCheckout($this);
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->call('review')->call('confirmCharge', true)->assertHasNoErrors();
    $proceso = ProcesoCobro::firstOrFail();
    expect(Cobro::count())->toBe(1)->and($pedido->fresh()->estado->value)->toBe('cerrado')->and(TrabajoImpresion::firstOrFail()->estado->value)->toBe('error')->and($proceso->error)->not->toBeNull();
    app(ProcesarVenta::class)->continuar($this->actor, $proceso->id);
    expect(Cobro::count())->toBe(1)->and(TrabajoImpresion::count())->toBe(1);
});

test('checkout can recover emission after a post-payment failure without charging or numbering twice', function () {
    $pedido = pedidoCheckout($this);
    $this->mock(EmitirComprobante::class)->shouldReceive('emitir')->once()->andThrow(new RuntimeException('Fallo local de emisión simulado'));
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->set('documento', '03')->call('review')->call('confirmCharge', true)->assertHasNoErrors();
    $proceso = ProcesoCobro::firstOrFail();
    expect(Cobro::count())->toBe(1)->and($proceso->finalizado_at)->toBeNull()->and($this->mesa->ocupacion()->exists())->toBeTrue()->and(Comprobante::count())->toBe(0);
    app()->forgetInstance(EmitirComprobante::class);
    app(ProcesarVenta::class)->continuar($this->actor, $proceso->id);
    app(ProcesarVenta::class)->continuar($this->actor, $proceso->id);
    expect(Cobro::count())->toBe(1)->and(Comprobante::count())->toBe(1)->and(TrabajoImpresion::count())->toBe(1)->and($pedido->fresh()->estado->value)->toBe('cerrado');
});

test('checkout confirmation changes both receiving cash box and printing destination together', function () {
    $pedido = pedidoCheckout($this);
    $otra = Caja::whereKeyNot($this->turno->caja_id)->firstOrFail();
    $otra->impresora->update(['activo' => true, 'host' => '127.0.0.1', 'puerto' => 19103]);
    $responsable = User::factory()->create();
    $responsable->assignRole('cajero');
    $turno = app(GestionCajas::class)->abrir($this->actor, $otra->id, $responsable->id, '0', '', (string) Str::uuid());
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->call('review')->set('turnoId', (string) $turno->id)->assertHasNoErrors()->call('confirmCharge', false)->assertHasNoErrors();
    expect(Cobro::firstOrFail()->turno_caja_id)->toBe($turno->id)->and(MovimientoCaja::firstOrFail()->turno_caja_id)->toBe($turno->id)->and(TrabajoImpresion::firstOrFail()->impresora_id)->toBe($otra->impresora_id);
});

test('checkout above-threshold boleta accepts a DNI client and does not create a login account', function () {
    $this->producto->update(['precio_venta' => '701.00']);
    $pedido = pedidoCheckout($this);
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->set('documento', '03')->call('abrirCliente')->set('nuevoNumero', '12345678')->set('nuevoNombre', 'Ana Prueba')->call('guardarCliente')->assertHasNoErrors()->call('review')->assertHasNoErrors()->call('confirmCharge', false)->assertHasNoErrors();
    expect(Comprobante::firstOrFail()->receptor['tipo'])->toBe('1')->and(Comprobante::firstOrFail()->receptor['nombre'])->toBe('Ana Prueba')->and(User::count())->toBe(1);
});

test('checkout recovers an already committed signed document without assigning a second correlativo', function () {
    $pedido = pedidoCheckout($this);
    $real = new EmitirComprobante;
    $this->mock(EmitirComprobante::class)->shouldReceive('emitir')->once()->andReturnUsing(function (...$args) use ($real) {
        $real->emitir(...$args);
        throw new RuntimeException('Interrupción posterior al documento firmado');
    });
    Livewire::test(CobroFormulario::class, ['pedido' => $pedido])->set('documento', '03')->call('review')->call('confirmCharge', false)->assertHasNoErrors();
    expect(Comprobante::count())->toBe(1)->and(TrabajoImpresion::count())->toBe(0);
    app()->forgetInstance(EmitirComprobante::class);
    ConfiguracionFacturacion::firstOrFail()->update(['habilitada' => false, 'entorno' => 'produccion']);
    app(ProcesarVenta::class)->continuar($this->actor, ProcesoCobro::firstOrFail()->id);
    expect(Comprobante::count())->toBe(1)->and(Comprobante::firstOrFail()->correlativo)->toBe(1)->and(Cobro::count())->toBe(1)->and(TrabajoImpresion::count())->toBe(1);
});
