<?php

use App\Livewire\Facturacion\ComprobantePantalla;
use App\Livewire\Facturacion\ConfiguracionFiscal;
use App\Livewire\Facturacion\EmisionCobro;
use App\Livewire\Impresion\DocumentosImpresion;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\MedioPago;
use App\Models\Mesa;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\SerieComprobante;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Cajas\GestionCajas;
use App\Services\Cobros\GestionCobros;
use App\Services\Facturacion\CalculoTributario;
use App\Services\Facturacion\CertificadoFiscal;
use App\Services\Facturacion\ConfigurarFacturacion;
use App\Services\Facturacion\EmitirComprobante;
use App\Services\Facturacion\EnviarComprobante;
use App\Services\Facturacion\FalloSunat;
use App\Services\Facturacion\RepresentacionFiscal;
use App\Services\Facturacion\RespuestaSunat;
use App\Services\Facturacion\TransporteSunat;
use App\Services\Facturacion\XmlFiscal;
use App\Services\Impresion\ImpresionOperativa;
use App\Services\Impresion\TicketOperativo;
use App\Services\Impresion\TransporteEscPos;
use App\Services\Pos\ConfirmarPedido;
use App\Services\Pos\GestionPedidos;
use Carbon\CarbonImmutable;
use Database\Seeders\EjemploInventarioSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Greenter\Ws\Services\SoapClient;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function cuentaFiscal(User $actor, TurnoCaja $turno, array $productos, ?Mesa $mesa = null, ?string $envio = null): Pedido
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
function seleccionFiscal(Pedido $pedido, ?array $porProducto = null): array
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
function pagoFiscal(string $codigo, string $monto, string $entregado = '', string $referencia = ''): array
{
    return ['medioId' => (string) MedioPago::where('codigo', $codigo)->firstOrFail()->id, 'monto' => $monto, 'entregado' => $entregado, 'referencia' => $referencia];
}
function datosFiscal(array $cambios = []): array
{
    return array_replace(['clienteId' => '', 'grupoPagador' => '', 'incluirEnvio' => false], $cambios);
}
function realizarCobroFiscal(User $actor, Pedido $pedido, TurnoCaja $turno, array $seleccion, array $pagos, array $datos = []): Cobro
{
    $datos = datosFiscal($datos);
    $plan = app(GestionCobros::class)->previsualizar($actor, $pedido->id, $turno->id, $datos, $seleccion, $pagos);

    return app(GestionCobros::class)->cobrar($actor, $pedido->id, $turno->id, (string) Str::uuid(), $plan['huella'], $datos, $seleccion, $pagos);
}

function certificadoPruebaFiscal(): string
{
    static $pfx;
    if ($pfx) {
        return $pfx;
    }
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'SisRest TEST ONLY'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
    openssl_pkcs12_export($cert, $pfx, $key, 'test-cert-password');

    return $pfx;
}
function fiscalCliente(string $tipo = '03'): array
{
    return ['serieId' => SerieComprobante::where('serie', $tipo === '01' ? 'F001' : 'B001')->where('entorno', 'beta')->firstOrFail()->id, 'tipo' => $tipo === '01' ? '6' : '0', 'numero' => $tipo === '01' ? '20131312955' : '', 'nombre' => 'Cliente & Prueba', 'direccion' => 'Av. Prueba 100'];
}
function emitirFiscal(Cobro $cobro, User $actor, string $tipo = '03', ?string $clave = null, array $afectaciones = []): Comprobante
{
    $filas = $cobro->detalles()->pluck('id')->mapWithKeys(fn ($id) => [$id => '10'])->all();
    if (bccomp($cobro->cargo_envio, '0', 2) > 0) {
        $filas['envio'] = '10';
    }

    return app(EmitirComprobante::class)->emitir($actor, $cobro->id, $clave ?? (string) Str::uuid(), fiscalCliente($tipo), array_replace($filas, $afectaciones), ConfigurarFacturacion::version(Empresa::firstOrFail(), ConfiguracionFacturacion::firstOrFail()));
}
function zipCdrFiscal(Comprobante $c, string $nombre, string $codigo = '0', string $ruc = '20131312955', string $referencia = '', bool $observado = false): string
{
    $referencia = $referencia ?: (str_contains($nombre, '-RC-') ? substr($nombre, 12) : XmlFiscal::numero($c));
    $xml = '<?xml version="1.0"?><ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'.($observado ? '<cbc:Note>Observación de prueba</cbc:Note>' : '').'<cac:ReceiverParty><cac:PartyIdentification><cbc:ID>'.$ruc.'</cbc:ID></cac:PartyIdentification></cac:ReceiverParty><cac:DocumentResponse><cac:Response><cbc:ReferenceID>'.$referencia.'</cbc:ReferenceID><cbc:ResponseCode>'.$codigo.'</cbc:ResponseCode><cbc:Description>Respuesta de prueba</cbc:Description></cac:Response></cac:DocumentResponse></ApplicationResponse>';
    $file = tempnam(sys_get_temp_dir(), 'test-cdr');
    $zip = new ZipArchive;
    $zip->open($file, ZipArchive::OVERWRITE);
    $zip->addFromString('R-'.$nombre.'.xml', $xml);
    $zip->close();
    $bytes = file_get_contents($file);
    unlink($file);

    return $bytes;
}

beforeEach(function () {
    $this->seed([RolesPermisosSeeder::class, EjemploInventarioSeeder::class]);
    Storage::fake('local');
    $this->actor = User::factory()->create();
    $this->actor->assignRole('administrador');
    $this->actingAs($this->actor);
    $this->turno = app(GestionCajas::class)->abrir($this->actor, Caja::firstOrFail()->id, $this->actor->id, '100', '', (string) Str::uuid());
    $this->producto = Producto::where('codigo', 'EJ-GASEOSA')->firstOrFail();
    $this->producto->articulo->update(['stock_actual' => '100']);
    $pedido = cuentaFiscal($this->actor, $this->turno, [$this->producto->id => 2]);
    $total = bcmul($this->producto->precio_venta, '2', 2);
    $this->cobro = realizarCobroFiscal($this->actor, $pedido, $this->turno, seleccionFiscal($pedido), ['p1' => pagoFiscal('efectivo', $total, $total)]);
    Empresa::firstOrFail()->update(['ruc' => '20131312955', 'razon_social' => 'Restaurante & Prueba SAC', 'direccion' => 'Av. Prueba 100', 'ubigeo' => '150101', 'departamento' => 'LIMA', 'provincia' => 'LIMA', 'distrito' => 'LIMA']);
    Storage::disk('local')->put('certificados/test.enc', Crypt::encryptString(certificadoPruebaFiscal()));
    ConfiguracionFacturacion::firstOrFail()->update(['habilitada' => true, 'entorno' => 'beta', 'usuario_sol' => 'MODDATOS', 'clave_sol' => 'moddatos', 'certificado_ruta' => 'certificados/test.enc', 'clave_certificado' => 'test-cert-password', 'tasa_impuesto' => '18', 'vigente_desde' => now('America/Lima')->toDateString()]);
    SerieComprobante::whereIn('serie', ['F001', 'B001'])->update(['activo' => true]);
});

test('signs an immutable invoice from a confirmed charge without changing money or stock', function () {
    $movimientos = MovimientoCaja::count();
    $inventario = MovimientoInventario::count();
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    expect($c->total)->toBe($this->cobro->total)->and($c->estado_sunat->value)->toBe('pendiente')->and($c->correlativo)->toBe(1);
    $xml = Storage::disk('local')->get($c->xml_ruta);
    expect($xml)->toContain('<ds:Signature')->toContain('Restaurante & Prueba SAC')->toContain('Cliente & Prueba');
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();
    expect(app(XmlFiscal::class)->hash($xml))->toBe($c->hash_documento);
    expect(bcadd($c->valor_venta, $c->impuestos, 2))->toBe($c->total);
    Empresa::firstOrFail()->update(['razon_social' => 'Nombre cambiado']);
    expect($c->fresh()->emisor['razon_social'])->toBe('Restaurante & Prueba SAC');
    expect(MovimientoCaja::count())->toBe($movimientos)->and(MovimientoInventario::count())->toBe($inventario);
    $same = emitirFiscal($this->cobro, $this->actor, '01');
    expect($same->id)->toBe($c->id)->and(Comprobante::count())->toBe(1)->and($c->serie->fresh()->ultimo_correlativo)->toBe(1);
});

test('refuses disabled billing invalid receivers and revoked permissions before allocating a number', function () {
    ConfiguracionFacturacion::firstOrFail()->update(['habilitada' => false]);
    expect(fn () => emitirFiscal($this->cobro, $this->actor))->toThrow(ValidationException::class);
    ConfiguracionFacturacion::firstOrFail()->update(['habilitada' => true]);
    $datos = fiscalCliente('01');
    $datos['numero'] = '11111111111';
    expect(fn () => app(EmitirComprobante::class)->emitir($this->actor, $this->cobro->id, (string) Str::uuid(), $datos, [$this->cobro->detalles->first()->id => '10'], ConfigurarFacturacion::version(Empresa::firstOrFail(), ConfiguracionFacturacion::firstOrFail())))->toThrow(ValidationException::class);
    $this->actor->update(['activo' => false]);
    $this->get(route('comprobantes.emitir', $this->cobro))->assertRedirect();
    expect(Comprobante::count())->toBe(0)->and(SerieComprobante::where('serie', 'F001')->first()->ultimo_correlativo)->toBe(0);
});

test('keeps final prices and calculates configured tax and discounts with decimal arithmetic', function () {
    $d = $this->cobro->detalles->first();
    $d->update(['subtotal' => '118.00', 'precio_unitario' => '59.00', 'descuento' => '11.80', 'total' => '106.20']);
    $this->cobro->update(['subtotal' => '118.00', 'descuento_productos' => '11.80', 'total' => '106.20']);
    $r = app(CalculoTributario::class)->calcular($this->cobro, '18.0000', [$d->id => '10']);
    expect($r['valor_venta'])->toBe('90.00')->and($r['impuestos'])->toBe('16.20')->and($r['lineas'][0]['descuento'])->toBe('10.00')->and($r['total'])->toBe('106.20');
    $r = app(CalculoTributario::class)->calcular($this->cobro, '10.5000', [$d->id => '10']);
    expect(bcadd($r['valor_venta'], $r['impuestos'], 2))->toBe('106.20');
    $r = app(CalculoTributario::class)->calcular($this->cobro, '18.0000', [$d->id => '20']);
    expect($r['exoneradas'])->toBe('106.20')->and($r['impuestos'])->toBe('0.00');
});

test('requires an explicit free transfer classification without adding its tax to the customer total', function () {
    $d = $this->cobro->detalles->first();
    $d->update(['subtotal' => '118.00', 'precio_unitario' => '59.00', 'descuento' => '118.00', 'total' => '0.00', 'es_cortesia' => true]);
    $this->cobro->update(['total' => '0.00']);
    expect(fn () => app(CalculoTributario::class)->calcular($this->cobro, '18.0000', [$d->id => '10']))->toThrow(ValidationException::class);
    $r = app(CalculoTributario::class)->calcular($this->cobro, '18.0000', [$d->id => '13']);
    expect($r['gratuitas'])->toBe('100.00')->and($r['igv_gratuitas'])->toBe('18.00')->and($r['impuestos'])->toBe('0.00')->and($r['total'])->toBe('0.00');
});

test('accepts an invoice only with its matching CDR and never sends it twice', function () {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $this->mock(TransporteSunat::class)->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(cdr: zipCdrFiscal($c, XmlFiscal::nombre($c))));
    $s = app(EnviarComprobante::class);
    $s->enviar($this->actor, $c->id);
    $s->enviar($this->actor, $c->id);
    expect($c->fresh()->estado_sunat->value)->toBe('aceptado')->and($c->envios()->count())->toBe(1)->and(Storage::disk('local')->exists($c->fresh()->cdr_ruta))->toBeTrue();
});

test('sends a boleta using a signed summary and accepts it after consulting its ticket', function () {
    $c = emitirFiscal($this->cobro, $this->actor);
    $mock = $this->mock(TransporteSunat::class);
    $mock->shouldReceive('enviar')->once()->withArgs(function ($config, $ruc, $tipo, $nombre, $xml) {
        return $tipo === '03' && str_contains($nombre, '-RC-') && str_contains($xml, 'SummaryDocuments') && str_contains($xml, '<ds:Signature');
    })->andReturn(new RespuestaSunat(ticket: 'TICKET-123'));
    app(EnviarComprobante::class)->enviar($this->actor, $c->id);
    expect($c->fresh()->estado_sunat->value)->toBe('enviado');
    $envio = $c->envios()->first();
    $mock->shouldReceive('consultar')->once()->andReturn(new RespuestaSunat(cdr: zipCdrFiscal($c, $envio->xml_nombre)));
    app(EnviarComprobante::class)->consultar($this->actor, $c->id);
    expect($c->fresh()->estado_sunat->value)->toBe('aceptado')->and($envio->fresh()->ticket)->toBe('TICKET-123');
});

test('preserves a pending summary ticket and records observed or rejected CDR responses', function (string $codigo, bool $observado, string $esperado) {
    $c = emitirFiscal($this->cobro, $this->actor);
    $mock = $this->mock(TransporteSunat::class);
    $mock->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(ticket: 'T-1'));
    app(EnviarComprobante::class)->enviar($this->actor, $c->id);
    $mock->shouldReceive('consultar')->once()->andReturn(new RespuestaSunat(pendiente: true, codigo: '98'));
    app(EnviarComprobante::class)->consultar($this->actor, $c->id);
    expect($c->fresh()->estado_sunat->value)->toBe('enviado');
    $nombre = $c->envios()->first()->xml_nombre;
    $mock->shouldReceive('consultar')->once()->andReturn(new RespuestaSunat(cdr: zipCdrFiscal($c, $nombre, $codigo, observado: $observado)));
    app(EnviarComprobante::class)->consultar($this->actor, $c->id);
    expect($c->fresh()->estado_sunat->value)->toBe($esperado)->and($this->cobro->fresh()->estado->value)->toBe('confirmado');
})->with([['0', true, 'observado'], ['2335', false, 'rechazado']]);

test('marks transport failures or mismatching CDR as uncertain without repeating the send', function (bool $fallo) {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $mock = $this->mock(TransporteSunat::class)->shouldReceive('enviar')->once();
    if ($fallo) {
        $mock->andThrow(new RuntimeException('sensitive upstream text'));
    } else {
        $mock->andReturn(new RespuestaSunat(cdr: zipCdrFiscal($c, XmlFiscal::nombre($c), ruc: '20999999999')));
    }
    app(EnviarComprobante::class)->enviar($this->actor, $c->id);
    app(EnviarComprobante::class)->enviar($this->actor, $c->id);
    expect($c->fresh()->estado_sunat->value)->toBe('desconocido')->and($c->envios()->first()->mensaje)->not->toContain('sensitive');
})->with([true, false]);

test('refuses a modified signed XML before any external request', function () {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    Storage::disk('local')->put($c->xml_ruta, 'tampered');
    $this->mock(TransporteSunat::class)->shouldNotReceive('enviar');
    expect(fn () => app(EnviarComprobante::class)->enviar($this->actor, $c->id))->toThrow(ValidationException::class);
    expect($c->envios()->count())->toBe(0);
});

test('protects fiscal screens downloads and private credentials from other users', function () {
    $c = emitirFiscal($this->cobro, $this->actor);
    $this->get(route('comprobantes.ver', $c))->assertOk()->assertSee('BETA');
    $this->get(route('comprobantes.archivo', [$c, 'xml']))->assertOk();
    $this->get(route('comprobantes.archivo', [$c, 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
    Livewire::test(ConfiguracionFiscal::class)->assertSet('facturacion.usuario_sol', '')->assertSet('facturacion.clave_sol', '')->assertSet('facturacion.clave_certificado', '');
    $user = User::factory()->create();
    $user->assignRole('cajero');
    $this->actingAs($user);
    $this->get(route('comprobantes.ver', $c))->assertForbidden();
    $this->get(route('comprobantes.archivo', [$c, 'xml']))->assertForbidden();
    $this->get(route('configuracion.facturacion'))->assertForbidden();
    expect(ConfiguracionFacturacion::first()->toArray())->not->toHaveKeys(['clave_sol', 'clave_certificado', 'certificado_ruta', 'usuario_sol']);
});

test('requires confirmation and revalidation before emitting from Livewire', function () {
    $component = Livewire::test(EmisionCobro::class, ['cobro' => $this->cobro]);
    $component->call('emitir')->assertHasErrors('facturacion');
    $component->call('solicitarEmision')->assertDispatched('app:confirm')->set('nombre', 'Cliente cambiado')->call('emitir')->assertHasErrors('facturacion');
    expect(Comprobante::count())->toBe(0);
    $component->call('solicitarEmision')->call('emitir')->assertRedirect();
    expect(Comprobante::count())->toBe(1);
});

test('prints fiscal documents with their own permission history and QR through the shared print service', function () {
    $c = emitirFiscal($this->cobro, $this->actor);
    $impresora = $this->turno->caja->impresora;
    $impresora->update(['activo' => true, 'host' => '192.168.1.55']);
    $component = Livewire::test(DocumentosImpresion::class, ['comprobante' => $c]);
    $component->assertSee('Imprimir comprobante electrónico');
    $solicitud = ['tipo' => 'comprobante', 'id' => $c->id, 'caja' => $this->turno->caja_id, 'area' => 0];
    $this->mock(TransporteEscPos::class)->shouldReceive('enviar')->once();
    $s = app(ImpresionOperativa::class);
    $plan = $s->previsualizar($this->actor, $solicitud);
    expect($plan['contenido']['qr'])->toBe(app(RepresentacionFiscal::class)->qr($c));

    $job = $s->imprimir($this->actor, $solicitud, 0, '', (string) Str::uuid(), $plan['huella']);
    expect($job->tipo->value)->toBe('comprobante')->and($job->documento_type)->toBe(Comprobante::class);
    $bytes = app(TicketOperativo::class)->generar('prueba', $plan['contenido']['qr']);
    expect($bytes)->toContain("\x1d(k");
});

function datosConfiguracionFiscal(): array
{
    $empresa = Empresa::firstOrFail();
    $datos = [];
    foreach (['ruc', 'razon_social', 'nombre_comercial', 'direccion', 'ubigeo', 'departamento', 'provincia', 'distrito', 'telefono', 'correo'] as $campo) {
        $datos[$campo] = (string) ($empresa->$campo ?? '');
    }

    return $datos;
}
function opcionesConfiguracionFiscal(array $cambios = []): array
{
    return array_replace(['habilitada' => true, 'entorno' => 'beta', 'tasa_impuesto' => '18', 'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => '', 'usuario_sol' => '', 'clave_sol' => '', 'clave_certificado' => '', 'confirmarProduccion' => false], $cambios);
}

test('stores credentials encrypted without showing them and rejects stale configuration updates', function () {
    $e = Empresa::firstOrFail();
    $c = ConfiguracionFacturacion::firstOrFail();
    $version = ConfigurarFacturacion::version($e, $c);
    app(ConfigurarFacturacion::class)->guardar($this->actor, $version, datosConfiguracionFiscal(), opcionesConfiguracionFiscal(['clave_sol' => 'secret-new-sol']), null);
    expect($c->fresh()->clave_sol)->toBe('secret-new-sol')->and($c->fresh()->getRawOriginal('clave_sol'))->not->toBe('secret-new-sol');
    expect(fn () => app(ConfigurarFacturacion::class)->guardar($this->actor, $version, datosConfiguracionFiscal(), opcionesConfiguracionFiscal(), null))->toThrow(ValidationException::class);
    $snapshot = Livewire::test(ConfiguracionFiscal::class)->assertSet('facturacion.clave_sol', '')->html();
    expect(json_encode($snapshot))->not->toContain('secret-new-sol')->not->toContain('test-cert-password');
});

test('requires fresh credentials certificate and explicit confirmation when switching to production', function () {
    $version = ConfigurarFacturacion::version(Empresa::firstOrFail(), ConfiguracionFacturacion::firstOrFail());
    expect(fn () => app(ConfigurarFacturacion::class)->guardar($this->actor, $version, datosConfiguracionFiscal(), opcionesConfiguracionFiscal(['entorno' => 'produccion', 'confirmarProduccion' => true]), null))->toThrow(ValidationException::class);
    expect(fn () => app(ConfigurarFacturacion::class)->guardar($this->actor, $version, datosConfiguracionFiscal(), opcionesConfiguracionFiscal(['entorno' => 'produccion', 'usuario_sol' => 'PRUEBA', 'clave_sol' => 'new-sol', 'clave_certificado' => 'test-cert-password']), certificadoPruebaFiscal()))->toThrow(ValidationException::class);
    app(ConfigurarFacturacion::class)->guardar($this->actor, $version, datosConfiguracionFiscal(), opcionesConfiguracionFiscal(['entorno' => 'produccion', 'usuario_sol' => 'PRUEBA', 'clave_sol' => 'new-sol', 'clave_certificado' => 'test-cert-password', 'confirmarProduccion' => true]), certificadoPruebaFiscal());
    expect(ConfiguracionFacturacion::first()->entorno->value)->toBe('produccion');
    $ruta = ConfiguracionFacturacion::first()->certificado_ruta;
    expect(Storage::disk('local')->get($ruta))->not->toContain('PRIVATE KEY');
});

test('separates production and beta series and allows a real document after a beta trial', function () {
    $beta = emitirFiscal($this->cobro, $this->actor);
    app(ConfigurarFacturacion::class)->crearSerie($this->actor, 'produccion', '03', 'B001', '10');
    $serie = SerieComprobante::where('entorno', 'produccion')->firstOrFail();
    app(ConfigurarFacturacion::class)->estadoSerie($this->actor, $serie->id, true);
    ConfiguracionFacturacion::first()->update(['entorno' => 'produccion']);
    $receptor = fiscalCliente();
    $receptor['serieId'] = $serie->id;
    $c = app(EmitirComprobante::class)->emitir($this->actor, $this->cobro->id, (string) Str::uuid(), $receptor, [$this->cobro->detalles->first()->id => '10'], ConfigurarFacturacion::version(Empresa::firstOrFail(), ConfiguracionFacturacion::firstOrFail()));
    expect($c->correlativo)->toBe(11)->and($c->entorno)->toBe('produccion')->and($beta->fresh()->serie->ultimo_correlativo)->toBe(1)->and($this->cobro->comprobantes()->count())->toBe(2);
    $this->get(route('pos.cobro', $this->cobro))->assertSee(route('comprobantes.ver', $c), false);
});

test('rejects a changed tax configuration after the cashier confirmed the emission', function () {
    $component = Livewire::test(EmisionCobro::class, ['cobro' => $this->cobro])->call('solicitarEmision');
    ConfiguracionFacturacion::first()->update(['tasa_impuesto' => '10.5']);
    $component->call('emitir')->assertHasErrors('facturacion');
    expect(Comprobante::count())->toBe(0);
});

test('recovers interrupted durable attempts without repeating a potentially completed send', function () {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $envio = $c->envios()->create(['intento' => 1, 'estado' => 'en_proceso', 'iniciado_at' => now()->subMinutes(3)]);
    $this->mock(TransporteSunat::class)->shouldNotReceive('enviar');
    Livewire::test(ComprobantePantalla::class, ['comprobante' => $c])->assertSee('Desconocido');
    app(EnviarComprobante::class)->enviar($this->actor, $c->id);
    expect($envio->fresh()->estado->value)->toBe('resultado_incierto');
});

test('checks the identity threshold and refuses tax emission after a confirmed refund', function () {
    $this->cobro->update(['total' => '701.00']);
    $this->cobro->pagos()->first()->update(['monto' => '701.00', 'monto_entregado' => '701.00']);
    $this->cobro->detalles()->first()->update(['subtotal' => '701.00', 'total' => '701.00']);
    expect(fn () => emitirFiscal($this->cobro, $this->actor))->toThrow(ValidationException::class);
    $this->cobro->devoluciones()->create(['codigo' => 'DEV-FISCAL', 'autorizado_por' => $this->actor->id, 'usuario_id' => $this->actor->id, 'turno_caja_id' => $this->turno->id, 'estado' => 'confirmada', 'moneda' => 'PEN', 'total' => '1.00', 'motivo' => 'Prueba de devolución', 'clave_idempotencia' => (string) Str::uuid()]);
    expect(fn () => emitirFiscal($this->cobro, $this->actor, '01'))->toThrow(ValidationException::class);
    expect(Comprobante::count())->toBe(0);
});

test('summary includes current tax rate receiver name and free transfer tax before a valid signature', function () {
    $d = $this->cobro->detalles->first();
    $d->update(['subtotal' => '118.00', 'precio_unitario' => '59.00', 'descuento' => '118.00', 'total' => '0.00', 'es_cortesia' => true]);
    $this->cobro->update(['total' => '0.00']);
    $this->cobro->pagos()->delete();
    $c = emitirFiscal($this->cobro, $this->actor, afectaciones: [$d->id => '13']);
    $summary = app(XmlFiscal::class)->resumen($c, 1, CarbonImmutable::now('America/Lima'));
    $xml = app(XmlFiscal::class)->firmar($summary, app(CertificadoFiscal::class)->cargar(ConfiguracionFacturacion::firstOrFail()), $c);
    expect((new SignedXml)->verifyXml($xml))->toBeTrue();
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    expect($xp->evaluate('string(//cac:TaxSubtotal[cac:TaxCategory/cac:TaxScheme/cbc:ID="9996"]/cbc:TaxAmount)'))->toBe('18.00');
    expect($xp->evaluate('string(//cac:TaxSubtotal[cac:TaxCategory/cac:TaxScheme/cbc:ID="1000"]/cac:TaxCategory/cbc:Percent)'))->toBe('18.0000');
    expect($xp->evaluate('string(//cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName)'))->toBe('Cliente & Prueba');
    expect((new SignedXml)->verifyXml(Storage::disk('local')->get($c->xml_ruta)))->toBeTrue();
});

test('boletas without customer address omit an empty optional XML address', function () {
    $component = Livewire::test(EmisionCobro::class, ['cobro' => $this->cobro])->call('solicitarEmision')->call('emitir');
    $c = Comprobante::firstOrFail();
    $xml = Storage::disk('local')->get($c->xml_ruta);
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    expect($xp->evaluate('count(//cac:AccountingCustomerParty//cac:RegistrationAddress)'))->toBe(0.0);
});

test('SOAP transport packages the exact frozen XML and distinguishes invoice CDR from summary ticket', function (string $tipo) {
    $c = emitirFiscal($this->cobro, $this->actor, $tipo);
    $nombre = XmlFiscal::nombre($c);
    $xml = Storage::disk('local')->get($c->xml_ruta);
    $config = ConfiguracionFacturacion::firstOrFail();
    $soap = Mockery::mock(SoapClient::class);
    $soap->shouldReceive('call')->once()->with($tipo === '03' ? 'sendSummary' : 'sendBill', Mockery::on(function ($args) use ($nombre, $xml) {
        if (($args['parameters']['fileName'] ?? null) !== $nombre.'.zip') {
            return false;
        }
        $file = tempnam(sys_get_temp_dir(), 'soap-zip');
        file_put_contents($file, $args['parameters']['contentFile']);
        $z = new ZipArchive;
        $ok = $z->open($file, ZipArchive::RDONLY) === true && $z->numFiles === 1 && $z->getFromName($nombre.'.xml') === $xml;
        $z->close();
        unlink($file);

        return $ok;
    }))->andReturn($tipo === '03' ? (object) ['ticket' => 'T-SOAP'] : (object) ['applicationResponse' => 'zip-returned-by-SUNAT']);
    $transport = Mockery::mock(TransporteSunat::class)->makePartial();
    $transport->shouldReceive('cliente')->once()->with($config, '20131312955')->andReturn($soap);
    $r = $transport->enviar($config, '20131312955', $tipo, $nombre, $xml);
    expect($tipo === '03' ? $r->ticket : $r->cdr)->toBe($tipo === '03' ? 'T-SOAP' : 'zip-returned-by-SUNAT');
})->with(['01', '03']);

test('XML line discount factor uses SUNAT precision without changing the net paid', function () {
    $this->cobro->detalles()->first()->update(['subtotal' => '100.00', 'precio_unitario' => '50.00', 'descuento' => '9.99', 'total' => '90.01']);
    $this->cobro->update(['subtotal' => '100.00', 'descuento_productos' => '9.99', 'total' => '90.01']);
    $this->cobro->pagos()->first()->update(['monto' => '90.01', 'monto_entregado' => '90.01']);
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $xml = Storage::disk('local')->get($c->xml_ruta);
    expect($xml)->toContain('<cbc:MultiplierFactorNumeric>0.09994</cbc:MultiplierFactorNumeric>')->and($c->total)->toBe('90.01');
    expect((new SignedXml)->verifyXml($xml))->toBeTrue();
});

test('fiscal thermal documents share the compact layout without losing fiscal identity totals or QR', function (string $tipo, int $ancho, int $columnas) {
    $c = emitirFiscal($this->cobro, $this->actor, $tipo);
    $representacion = app(RepresentacionFiscal::class);
    $contenido = $representacion->contenido($c);
    $texto = app(TicketOperativo::class)->texto($contenido, $ancho);
    expect($texto)->toContain($tipo === '01' ? 'FACTURA ELECTRONICA' : 'BOLETA DE VENTA ELECTRONICA', 'DETALLE DE CONSUMO', 'P.U.', 'IMP.', 'IGV / IPM', 'TOTAL', 'SUNAT:', 'Hash:', 'BETA - PRUEBA SIN VALIDEZ')->not->toContain('Precio unitario:')
        ->and($contenido['qr'])->toBe($representacion->qr($c))->and($contenido['totales'][3]['monto'])->toBe($c->total);
    foreach (explode("\n", $texto) as $linea) {
        expect(strlen($linea))->toBeLessThanOrEqual($columnas);
    }
    $bytes = app(TicketOperativo::class)->generar($texto, $contenido['qr'], null, null, $ancho);
    expect(str_contains($bytes, "\x1d(k"))->toBeTrue();
})->with(['03', '01'])->with([[58, 32], [80, 48]]);

test('persists a safe original diagnostic and never resends an uncertain invoice', function (string $tipo) {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    Log::spy();
    $this->mock(TransporteSunat::class)->shouldReceive('enviar')->once()->andThrow(new FalloSunat($tipo, $tipo === 'soap_autenticacion' ? '0102' : null));
    $service = app(EnviarComprobante::class);
    $service->enviar($this->actor, $c->id);
    $primero = $c->envios()->firstOrFail();
    $diagnostico = $primero->diagnostico_envio;
    expect($diagnostico['tipo'])->toBe($tipo)->and($diagnostico['etapa'])->toBe('transporte')->and($diagnostico['operacion'])->toBe('envio');
    $service->enviar($this->actor, $c->id);
    expect($primero->fresh()->diagnostico_envio)->toBe($diagnostico)->and($c->envios()->count())->toBe(1)->and($c->fresh()->estado_sunat->value)->toBe('desconocido');
    Log::shouldHaveReceived('warning')->once()->with('Diagnóstico seguro SUNAT', Mockery::on(fn ($context) => $context['referencia'] === $diagnostico['referencia'] && $context['entorno'] === 'beta' && ! isset($context['exception'])));
})->with(['conexion_timeout', 'soap_autenticacion', 'soap_sunat', 'respuesta_invalida', 'zip_preparacion']);

test('quarantines an invalid CDR before parsing and exposes only a safe local diagnosis', function (bool $incorrecto) {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $contenido = $incorrecto ? zipCdrFiscal($c, XmlFiscal::nombre($c), ruc: '20999999999') : 'SECRET malformed ZIP';
    $this->mock(TransporteSunat::class)->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(cdr: $contenido));
    app(EnviarComprobante::class)->enviar($this->actor, $c->id);
    $envio = $c->envios()->firstOrFail();
    expect($envio->diagnostico_envio['tipo'])->toBe($incorrecto ? 'cdr_referencia' : 'cdr_zip')->and($envio->diagnostico_envio['etapa'])->toBe('cdr_lectura')
        ->and($envio->cdr_recibido_sha256)->toBe(hash('sha256', $contenido))->and(Storage::disk('local')->get($envio->cdr_recibido_ruta))->toBe($contenido)
        ->and($envio->cdr_ruta)->toBeNull()->and($c->fresh()->cdr_ruta)->toBeNull()->and($c->fresh()->estado_sunat->value)->toBe('desconocido');
    $this->get(route('comprobantes.ver', $c))->assertOk()->assertSee($envio->diagnostico_envio['referencia'])->assertDontSee('SECRET');
    $antes = $envio->refresh()->getAttributes();
    $this->mock(TransporteSunat::class)->shouldNotReceive('consultar');
    $this->artisan('facturacion:diagnosticar', ['numero' => 'F001-1', '--entorno' => 'beta', '--usuario' => $this->actor->id])->expectsOutputToContain('Revisión local de solo lectura')->assertSuccessful();
    expect($envio->fresh()->getAttributes())->toBe($antes);
})->with([true, false]);

test('preserves original evidence and original diagnostic when a consultation also fails', function () {
    $c = emitirFiscal($this->cobro, $this->actor);
    $this->mock(TransporteSunat::class)->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(cdr: 'bad CDR ORIGINAL'));
    $service = app(EnviarComprobante::class);
    $service->enviar($this->actor, $c->id);
    $envio = $c->envios()->firstOrFail();
    $original = $envio->diagnostico_envio;
    $ruta = $envio->cdr_recibido_ruta;
    $envio->update(['ticket' => 'T-TEST']);
    $this->mock(TransporteSunat::class)->shouldReceive('consultar')->once()->andThrow(new SoapFault('HTTP', 'Connection timed out SOL-SECRET'));
    $service->consultar($this->actor, $c->id);
    expect($envio->fresh()->diagnostico_envio)->toBe($original)->and($envio->fresh()->cdr_recibido_ruta)->toBe($ruta)
        ->and($envio->fresh()->diagnostico_consulta['tipo'])->toBe('conexion_timeout')->and($envio->fresh()->consultando)->toBeFalse()
        ->and(json_encode($envio->fresh()->toArray()))->not->toContain('SOL-SECRET');
});

test('diagnoses old F001-1 offline without inventing its original cause or changing Beta', function () {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $envio = $c->envios()->create(['intento' => 1, 'estado' => 'resultado_incierto', 'iniciado_at' => now(), 'xml_ruta' => $c->xml_ruta, 'xml_nombre' => XmlFiscal::nombre($c), 'xml_sha256' => XmlFiscal::dato($c, 'xml_sha256'), 'mensaje' => 'No se obtuvo una respuesta verificable.']);
    $c->update(['estado_sunat' => 'desconocido']);
    $antes = $envio->refresh()->getAttributes();
    $this->mock(TransporteSunat::class)->shouldNotReceive('enviar')->shouldNotReceive('consultar');
    $this->artisan('facturacion:diagnosticar', ['numero' => 'F001-1', '--entorno' => 'beta', '--usuario' => $this->actor->id])->expectsOutputToContain('no fue conservado')->expectsOutputToContain('SHA-256 coincide')->assertSuccessful();
    expect($envio->fresh()->getAttributes())->toBe($antes)->and($c->fresh()->estado_sunat->value)->toBe('desconocido')->and(ConfiguracionFacturacion::firstOrFail()->entorno->value)->toBe('beta');
    expect(fn () => app(EnviarComprobante::class)->consultar($this->actor, $c->id))->toThrow(ValidationException::class);
});

test('offline diagnostics enforce active user permissions and require explicit environment', function () {
    emitirFiscal($this->cobro, $this->actor, '01');
    $user = User::factory()->create();
    $this->artisan('facturacion:diagnosticar', ['numero' => 'F001-1', '--entorno' => 'beta', '--usuario' => $user->id])->expectsOutputToContain('no está autorizado')->assertFailed();
    $this->artisan('facturacion:diagnosticar', ['numero' => 'F001-1', '--usuario' => $this->actor->id])->assertFailed();
    $this->actor->update(['activo' => false]);
    $this->artisan('facturacion:diagnosticar', ['numero' => 'F001-1', '--entorno' => 'beta', '--usuario' => $this->actor->id])->assertFailed();
});

test('logs only allowlisted metadata even when SOAP fault includes credentials XML and private paths', function () {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    Log::spy();
    $fault = new SoapFault('soap-env:Client.0102', 'SOL-SECRET /private/key.pem <Password>SECRET</Password>', null, ['certificate' => 'PRIVATE-CERTIFICATE']);
    $this->mock(TransporteSunat::class)->shouldReceive('enviar')->once()->andThrow($fault);
    app(EnviarComprobante::class)->enviar($this->actor, $c->id);
    $envio = $c->envios()->firstOrFail();
    expect($envio->diagnostico_envio['codigo_sunat'])->toBe('0102')->and(json_encode($envio->toArray()))->not->toContain('SOL-SECRET', 'PRIVATE-CERTIFICATE', '/private/key.pem', '<Password>');
    Log::shouldHaveReceived('warning')->once()->with('Diagnóstico seguro SUNAT', Mockery::on(fn ($context) => ! array_diff(array_keys($context), ['categoria', 'tipo', 'codigo_sunat', 'mensaje', 'referencia', 'etapa', 'operacion', 'fecha', 'envio_id', 'comprobante_id', 'entorno']) && ! str_contains(json_encode($context), 'SECRET') && $context['codigo_sunat'] === '0102'));
});

test('distinguishes private CDR storage failure from SUNAT rejection', function () {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $cdr = zipCdrFiscal($c, XmlFiscal::nombre($c));
    $this->mock(TransporteSunat::class)->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(cdr: $cdr));
    $disk = Storage::disk('local');
    $mock = Mockery::mock(FilesystemAdapter::class);
    $mock->shouldReceive('exists')->andReturnUsing(fn ($path) => $disk->exists($path));
    $mock->shouldReceive('get')->andReturnUsing(fn ($path) => $disk->get($path));
    $mock->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('local')->andReturn($mock);
    app(EnviarComprobante::class)->enviar($this->actor, $c->id);
    $envio = $c->envios()->firstOrFail();
    expect($envio->diagnostico_envio['tipo'])->toBe('cdr_guardado')->and($envio->diagnostico_envio['etapa'])->toBe('cdr_guardado')
        ->and($c->fresh()->estado_sunat->value)->toBe('desconocido')->and($envio->cdr_ruta)->toBeNull();
});
