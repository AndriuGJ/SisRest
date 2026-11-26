<?php

use App\Livewire\Facturacion\ComprobantePantalla;
use App\Livewire\Facturacion\ConfiguracionFiscal;
use App\Livewire\Facturacion\EmisionCobro;
use App\Livewire\Impresion\DocumentosImpresion;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ComprobanteEnvio;
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
use App\Services\Facturacion\LeerCdr;
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
use Greenter\Ws\Services\WsdlProvider;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
function zipCdrFiscal(Comprobante $c, string $nombre, string $codigo = '0', string $ruc = '20131312955', string $referencia = '', bool $observado = false, string $nota = 'Observación de prueba'): string
{
    $referencia = $referencia ?: (str_contains($nombre, '-RC-') ? substr($nombre, 12) : XmlFiscal::numero($c));
    $xml = '<?xml version="1.0"?><ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'.($observado ? '<cbc:Note>'.htmlspecialchars($nota, ENT_XML1).'</cbc:Note>' : '').'<cac:ReceiverParty><cac:PartyIdentification><cbc:ID>'.$ruc.'</cbc:ID></cac:PartyIdentification></cac:ReceiverParty><cac:DocumentResponse><cac:Response><cbc:ReferenceID>'.$referencia.'</cbc:ReferenceID><cbc:ResponseCode>'.$codigo.'</cbc:ResponseCode><cbc:Description>Respuesta de prueba</cbc:Description></cac:Response></cac:DocumentResponse></ApplicationResponse>';
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
    Log::shouldHaveReceived('warning')->once()->with('Diagnóstico seguro SUNAT', Mockery::on(fn ($context) => ! array_diff(array_keys($context), ['categoria', 'tipo', 'codigo_sunat', 'estado_http', 'mensaje', 'referencia', 'etapa', 'operacion', 'fecha', 'metodo_soap', 'servicio', 'envio_id', 'comprobante_id', 'entorno']) && ! str_contains(json_encode($context), 'SECRET') && $context['codigo_sunat'] === '0102'));
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

/** Simulate the previous parser failure after SUNAT returned and the ZIP was captured. */
function intentoRecuperableFiscal(Comprobante $c, User $actor, string $zip): ComprobanteEnvio
{
    $transport = Mockery::mock(TransporteSunat::class);
    $transport->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(cdr: $zip));
    $transport->shouldNotReceive('consultar');
    app()->instance(TransporteSunat::class, $transport);
    $lector = Mockery::mock(LeerCdr::class);
    $lector->shouldReceive('leer')->once()->andThrow(new FalloSunat('cdr_nombre'));
    app()->instance(LeerCdr::class, $lector);
    app(EnviarComprobante::class)->enviar($actor, $c->id);
    app()->instance(LeerCdr::class, new LeerCdr);

    return $c->envios()->firstOrFail();
}

function directoriosCdrFiscal(string $bytes, string $directorio = 'sunat/'): string
{
    $file = tempnam(sys_get_temp_dir(), 'test-cdr-dir');
    try {
        file_put_contents($file, $bytes);
        $zip = new ZipArchive;
        $zip->open($file);
        $zip->addEmptyDir($directorio);
        $zip->close();

        return file_get_contents($file);
    } finally {
        unlink($file);
    }
}

test('recovers the stored CDR locally with atomic audit and preserves original diagnostics and history', function (string $codigo, bool $observado, string $estado) {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $bytes = directoriosCdrFiscal(zipCdrFiscal($c, XmlFiscal::nombre($c), $codigo, observado: $observado, nota: '4093 - El codigo de ubigeo del domicilio fiscal del emisor no es válido'));
    $envio = intentoRecuperableFiscal($c, $this->actor, $bytes);
    $envio->update(['diagnostico_consulta' => ['tipo' => 'conexion_timeout']]);
    $original = $envio->refresh()->getAttributes();
    $documentoOriginal = $c->fresh()->getAttributes();
    $caja = MovimientoCaja::count();
    $stock = MovimientoInventario::count();
    ConfiguracionFacturacion::firstOrFail()->update(['habilitada' => false, 'usuario_sol' => null, 'clave_sol' => null]);
    $service = app(EnviarComprobante::class);
    $resultado = $service->reprocesarCdr($this->actor, $c->id, $envio->id);
    expect($resultado->estado_sunat->value)->toBe($estado)->and($resultado->cdr_ruta)->toBe($envio->cdr_recibido_ruta)
        ->and($envio->fresh()->codigo_respuesta)->toBe($codigo)->and($envio->fresh()->mensaje)->toContain($original['mensaje'], 'CDR reprocesado localmente:')
        ->and(Storage::disk('local')->get($envio->cdr_recibido_ruta))->toBe($bytes)
        ->and(Arr::except($envio->fresh()->getAttributes(), ['estado', 'codigo_respuesta', 'cdr_ruta', 'mensaje', 'updated_at']))->toBe(Arr::except($original, ['estado', 'codigo_respuesta', 'cdr_ruta', 'mensaje', 'updated_at']))
        ->and(Arr::except($resultado->getAttributes(), ['estado_sunat', 'cdr_ruta', 'updated_at']))->toBe(Arr::except($documentoOriginal, ['estado_sunat', 'cdr_ruta', 'updated_at']))
        ->and($c->envios()->count())->toBe(1)->and(Comprobante::count())->toBe(1)->and(MovimientoCaja::count())->toBe($caja)->and(MovimientoInventario::count())->toBe($stock);
    if ($observado) {
        expect($envio->fresh()->mensaje)->toContain('4093');
    }
    $audit = Activity::where('description', 'CDR almacenado reprocesado localmente')->sole();
    expect($audit->causer_id)->toBe($this->actor->id)->and($audit->subject_id)->toBe($c->id)->and($audit->properties->get('estado_sunat'))->toBe($estado)
        ->and($audit->properties->get('cdr_sha256'))->toBe(hash('sha256', $bytes));
    $despues = $envio->fresh()->getAttributes();
    $service->reprocesarCdr($this->actor, $c->id, $envio->id);
    expect($envio->fresh()->getAttributes())->toBe($despues)->and(Activity::where('description', 'CDR almacenado reprocesado localmente')->count())->toBe(1);
})->with([['0', true, 'observado'], ['0', false, 'aceptado'], ['2335', false, 'rechazado']]);

test('refuses missing altered or oversized stored CDR evidence without any state or history mutation', function (string $caso) {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $envio = intentoRecuperableFiscal($c, $this->actor, zipCdrFiscal($c, XmlFiscal::nombre($c)));
    match ($caso) {
        'ausente' => Storage::disk('local')->delete($envio->cdr_recibido_ruta),
        'alterado' => Storage::disk('local')->put($envio->cdr_recibido_ruta, 'altered SECRET'),
        'grande' => Storage::disk('local')->put($envio->cdr_recibido_ruta, str_repeat('x', 2 * 1024 * 1024 + 1)),
        'sin_hash' => $envio->update(['cdr_recibido_sha256' => null]),
        'ruta_ajena' => $envio->update(['cdr_recibido_ruta' => 'comprobantes/beta/other/received.zip']),
    };
    $antes = $envio->refresh()->getAttributes();
    expect(fn () => app(EnviarComprobante::class)->reprocesarCdr($this->actor, $c->id, $envio->id))->toThrow(ValidationException::class);
    expect($c->fresh()->estado_sunat->value)->toBe('desconocido')->and($envio->fresh()->getAttributes())->toBe($antes)
        ->and(Activity::where('description', 'CDR almacenado reprocesado localmente')->count())->toBe(0);
})->with(['ausente', 'alterado', 'grande', 'sin_hash', 'ruta_ajena']);

test('validates CDR content reference and fiscal outcome even when its stored hash matches', function (string $caso) {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $bytes = match ($caso) {
        'ruc' => zipCdrFiscal($c, XmlFiscal::nombre($c), ruc: '20999999999'),
        'referencia' => zipCdrFiscal($c, XmlFiscal::nombre($c), referencia: 'F001-999'),
        'nombre' => zipCdrFiscal($c, '20131312955-01-F001-999'),
        'codigo' => zipCdrFiscal($c, XmlFiscal::nombre($c), '0100'),
        'zip' => 'SECRET not a ZIP',
        'directorio_inseguro' => directoriosCdrFiscal(zipCdrFiscal($c, XmlFiscal::nombre($c)), '../unsafe/'),
    };
    $envio = intentoRecuperableFiscal($c, $this->actor, $bytes);
    $antes = $envio->getAttributes();
    expect(fn () => app(EnviarComprobante::class)->reprocesarCdr($this->actor, $c->id, $envio->id))->toThrow($caso === 'codigo' ? ValidationException::class : FalloSunat::class);
    expect($c->fresh()->estado_sunat->value)->toBe('desconocido')->and($envio->fresh()->getAttributes())->toBe($antes)
        ->and(Activity::where('description', 'CDR almacenado reprocesado localmente')->count())->toBe(0);
})->with(['ruc', 'referencia', 'nombre', 'codigo', 'zip', 'directorio_inseguro']);

test('local recovery enforces permissions inactive users target identity and in-flight consultation locks', function () {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $envio = intentoRecuperableFiscal($c, $this->actor, zipCdrFiscal($c, XmlFiscal::nombre($c)));
    $user = User::factory()->create();
    $service = app(EnviarComprobante::class);
    expect(fn () => $service->reprocesarCdr($user, $c->id, $envio->id))->toThrow(AuthorizationException::class);
    expect(fn () => $service->reprocesarCdr($this->actor, $c->id, $envio->id + 1))->toThrow(ModelNotFoundException::class);
    $envio->update(['consultando' => true]);
    expect(fn () => $service->reprocesarCdr($this->actor, $c->id, $envio->id))->toThrow(ValidationException::class);
    $envio->update(['consultando' => false]);
    $this->actor->update(['activo' => false]);
    expect(fn () => $service->reprocesarCdr($this->actor, $c->id, $envio->id))->toThrow(HttpException::class);
    expect($c->fresh()->estado_sunat->value)->toBe('desconocido')->and($envio->fresh()->cdr_ruta)->toBeNull();
});

test('Livewire local recovery uses confirmation locked identifiers and reports hash changes after review', function () {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $envio = intentoRecuperableFiscal($c, $this->actor, directoriosCdrFiscal(zipCdrFiscal($c, XmlFiscal::nombre($c), observado: true)));
    Livewire::test(ComprobantePantalla::class, ['comprobante' => $c])->call('reprocesarCdr')->assertForbidden();
    $screen = Livewire::test(ComprobantePantalla::class, ['comprobante' => $c])->assertSee('Reprocesar CDR almacenado')
        ->call('solicitarReprocesamiento', $envio->id)->assertSet('reprocesarEnvioId', $envio->id);
    Storage::disk('local')->put($envio->cdr_recibido_ruta, 'tampered after confirmation');
    $screen->call('reprocesarCdr')->assertHasErrors('facturacion')->assertSet('reprocesarEnvioId', null);
    expect($c->fresh()->estado_sunat->value)->toBe('desconocido');
});

test('Livewire local recovery registers observations once and forbids identifier tampering', function () {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $envio = intentoRecuperableFiscal($c, $this->actor, directoriosCdrFiscal(zipCdrFiscal($c, XmlFiscal::nombre($c), observado: true)));
    expect(fn () => Livewire::test(ComprobantePantalla::class, ['comprobante' => $c])->set('reprocesarEnvioId', $envio->id))->toThrow(CannotUpdateLockedPropertyException::class);
    Livewire::test(ComprobantePantalla::class, ['comprobante' => $c])->call('solicitarReprocesamiento', $envio->id)->call('reprocesarCdr')->assertHasNoErrors()->assertSet('reprocesarEnvioId', null)->assertDontSee('Reprocesar CDR almacenado');
    expect($c->fresh()->estado_sunat->value)->toBe('observado')->and($envio->fresh()->diagnostico_envio['tipo'])->toBe('cdr_nombre');
});

test('local recovery cannot replace a definitive fiscal result with a different stored CDR', function () {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $envio = intentoRecuperableFiscal($c, $this->actor, zipCdrFiscal($c, XmlFiscal::nombre($c)));
    $service = app(EnviarComprobante::class);
    $service->reprocesarCdr($this->actor, $c->id, $envio->id);
    $otro = zipCdrFiscal($c, XmlFiscal::nombre($c), '2335');
    Storage::disk('local')->put($envio->cdr_recibido_ruta, $otro);
    $envio->update(['cdr_recibido_sha256' => hash('sha256', $otro)]);
    $antes = $envio->refresh()->getAttributes();
    expect(fn () => $service->reprocesarCdr($this->actor, $c->id, $envio->id))->toThrow(ValidationException::class);
    expect($c->fresh()->estado_sunat->value)->toBe('aceptado')->and($envio->fresh()->getAttributes())->toBe($antes)
        ->and(Activity::where('description', 'CDR almacenado reprocesado localmente')->count())->toBe(1);
});

test('local recovery rolls back the fiscal result if its audit cannot be written', function () {
    $c = emitirFiscal($this->cobro, $this->actor, '01');
    $envio = intentoRecuperableFiscal($c, $this->actor, zipCdrFiscal($c, XmlFiscal::nombre($c)));
    $antes = $envio->getAttributes();
    Event::listen('eloquent.creating: '.Activity::class, function ($activity) {
        if ($activity->description === 'CDR almacenado reprocesado localmente') {
            throw new RuntimeException('simulated audit failure');
        }
    });
    expect(fn () => app(EnviarComprobante::class)->reprocesarCdr($this->actor, $c->id, $envio->id))->toThrow(RuntimeException::class, 'simulated audit failure');
    expect($c->fresh()->estado_sunat->value)->toBe('desconocido')->and($c->fresh()->cdr_ruta)->toBeNull()->and($envio->fresh()->getAttributes())->toBe($antes)
        ->and(Activity::where('description', 'CDR almacenado reprocesado localmente')->count())->toBe(0);
});

test('routes summary ticket queries exclusively to Beta billService with getStatus parameters', function () {
    $c = emitirFiscal($this->cobro, $this->actor);
    $config = ConfiguracionFacturacion::firstOrFail();
    $transport = Mockery::mock(TransporteSunat::class)->makePartial();
    expect($transport->endpoint($config))->toBe('https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService');
    $soap = Mockery::mock(SoapClient::class);
    $soap->shouldReceive('call')->once()->with('getStatus', ['parameters' => ['ticket' => 'T-BETA-QUERY']])->andReturn((object) ['status' => (object) ['statusCode' => '98']]);
    $transport->shouldReceive('cliente')->once()->with($config, '20131312955')->andReturn($soap);
    $r = $transport->consultar($config, $c, 'T-BETA-QUERY');
    expect($r->pendiente)->toBeTrue()->and($r->codigo)->toBe('98')->and($r->cdr)->toBeNull();
    expect(fn () => $transport->endpoint($config, true))->toThrow(ValidationException::class);
});

test('blocks a configuration environment mismatch before resolving any SOAP client', function () {
    $c = emitirFiscal($this->cobro, $this->actor);
    $snapshot = $c->configuracion_tributaria;
    $snapshot['entorno'] = 'not-beta';
    $c->configuracion_tributaria = $snapshot;
    $transport = Mockery::mock(TransporteSunat::class)->makePartial();
    $transport->shouldNotReceive('cliente');
    expect(fn () => $transport->consultar(ConfiguracionFacturacion::firstOrFail(), $c, 'T-BETA'))->toThrow(FalloSunat::class, 'entorno');
});

test('failed ticket queries retain the pending summary ticket original send record and append safe audit history', function (string $faultcode, string $message, string $tipo, ?int $http) {
    $c = emitirFiscal($this->cobro, $this->actor);
    $transport = $this->mock(TransporteSunat::class);
    $transport->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(ticket: 'T-PRESERVED-SECRET'));
    $service = app(EnviarComprobante::class);
    $service->enviar($this->actor, $c->id);
    $envio = $c->envios()->firstOrFail();
    $envio->update(['diagnostico_envio' => (new FalloSunat('error_no_clasificado'))->seguro() + ['referencia' => 'ORIGINAL', 'etapa' => 'registro_respuesta', 'operacion' => 'envio', 'fecha' => now()->toIso8601String()]]);
    $original = $envio->refresh()->getAttributes();
    Log::spy();
    $transport->shouldReceive('consultar')->twice()->andThrow(new SoapFault($faultcode, $message, null, ['Password' => 'SOL-SECRET', 'cert' => 'PRIVATE-CERT']));
    $service->consultar($this->actor, $c->id);
    $primera = $envio->fresh()->diagnostico_consulta['referencia'];
    $service->consultar($this->actor, $c->id);
    $actual = $envio->fresh();
    expect($actual->diagnostico_consulta['tipo'])->toBe($tipo)->and($actual->diagnostico_consulta['estado_http'])->toBe($http)
        ->and($actual->diagnostico_consulta['metodo_soap'])->toBe('getStatus')->and($actual->diagnostico_consulta['servicio'])->toBe('fe_beta')
        ->and($actual->diagnostico_consulta['referencia'])->not->toBe($primera)->and($actual->consultando)->toBeFalse()
        ->and($actual->consulta_iniciada_at)->not->toBeNull()->and($actual->consulta_finalizada_at)->not->toBeNull()
        ->and(Arr::except($actual->getAttributes(), ['consultando', 'consulta_iniciada_at', 'consulta_finalizada_at', 'diagnostico_consulta', 'updated_at']))->toBe(Arr::except($original, ['consultando', 'consulta_iniciada_at', 'consulta_finalizada_at', 'diagnostico_consulta', 'updated_at']))
        ->and($c->fresh()->estado_sunat->value)->toBe('enviado')->and($c->fresh()->cdr_ruta)->toBeNull()->and($c->envios()->count())->toBe(1);
    $audits = Activity::where('description', 'Consulta SUNAT sin respuesta verificable')->get();
    expect($audits)->toHaveCount(2)->and($audits->first()->properties->get('referencia'))->toBe($primera)
        ->and(json_encode($audits->pluck('properties')->all()))->not->toContain('SOL-SECRET', 'PRIVATE-CERT', 'T-PRESERVED-SECRET', 'Password');
    Log::shouldHaveReceived('warning')->twice()->with('Diagnóstico seguro SUNAT', Mockery::on(fn ($context) => ! str_contains(json_encode($context), 'SECRET') && ! str_contains(json_encode($context), 'PRIVATE-CERT')));
    $service->enviar($this->actor, $c->id); // One send only, even after repeated consultation failures.
    $this->get(route('comprobantes.ver', $c))->assertOk()->assertSee('pendiente de CDR verificable')->assertSee('getStatus');
})->with([
    ['HTTP', 'Internal Server Error', 'http_servidor', 500],
    ['HTTP', 'Service Unavailable SOL-SECRET', 'http_servidor', 503],
    ['HTTP', 'Error Fetching http headers', 'http_sin_cabeceras', null],
    ['soap-env:Server.200', 'Failed to establish a backside connection SOL-SECRET', 'soap_sunat', null],
    ['soap-env:Client.0102', 'SOL-SECRET', 'soap_autenticacion', null],
    ['Client', 'looks like we got no XML document', 'soap_decodificacion', null],
]);

test('a status code without a validated CDR never accepts a summary or clears its ticket', function (string $codigo) {
    $c = emitirFiscal($this->cobro, $this->actor);
    $this->mock(TransporteSunat::class)->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(ticket: 'T-PENDING'));
    $service = app(EnviarComprobante::class);
    $service->enviar($this->actor, $c->id);
    $envio = $c->envios()->firstOrFail();
    $soap = Mockery::mock(SoapClient::class);
    $soap->shouldReceive('call')->once()->with('getStatus', ['parameters' => ['ticket' => 'T-PENDING']])->andReturn((object) ['status' => (object) ['statusCode' => $codigo]]);
    $transport = Mockery::mock(TransporteSunat::class)->makePartial();
    $transport->shouldReceive('cliente')->once()->andReturn($soap);
    app()->instance(TransporteSunat::class, $transport);
    $service->consultar($this->actor, $c->id);
    expect($c->fresh()->estado_sunat->value)->toBe('enviado')->and($envio->fresh()->estado->value)->toBe('enviado')
        ->and($envio->fresh()->ticket)->toBe('T-PENDING')->and($envio->fresh()->codigo_respuesta)->toBe($codigo)->and($c->fresh()->cdr_ruta)->toBeNull();
})->with(['98', '0', '99', '0127']);

test('malformed contradictory or invalid CDR query responses preserve the ticket without acceptance', function (string $caso) {
    $c = emitirFiscal($this->cobro, $this->actor);
    $this->mock(TransporteSunat::class)->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(ticket: 'T-INVALID'));
    $service = app(EnviarComprobante::class);
    $service->enviar($this->actor, $c->id);
    $response = match ($caso) {
        'estructura' => (object) ['unexpected' => 'SECRET'],
        'codigo' => (object) ['status' => (object) ['statusCode' => 'SECRET']],
        'contradictorio' => (object) ['status' => (object) ['statusCode' => '98', 'content' => 'SECRET']],
        'cdr' => (object) ['status' => (object) ['statusCode' => '0', 'content' => 'SECRET not ZIP']],
        'cdr_no_definitivo' => (object) ['status' => (object) ['statusCode' => '0', 'content' => zipCdrFiscal($c, $c->envios()->firstOrFail()->xml_nombre, '0100')]],
    };
    $soap = Mockery::mock(SoapClient::class);
    $soap->shouldReceive('call')->once()->andReturn($response);
    $transport = Mockery::mock(TransporteSunat::class)->makePartial();
    $transport->shouldReceive('cliente')->once()->andReturn($soap);
    app()->instance(TransporteSunat::class, $transport);
    $service->consultar($this->actor, $c->id);
    $envio = $c->envios()->firstOrFail();
    expect($c->fresh()->estado_sunat->value)->toBe('enviado')->and($envio->ticket)->toBe('T-INVALID')->and($envio->estado->value)->toBe('enviado')
        ->and($envio->diagnostico_consulta['tipo'])->toBe(match ($caso) {
            'cdr' => 'cdr_zip', 'cdr_no_definitivo' => 'cdr_no_definitivo', default => 'respuesta_invalida'
        })->and($envio->cdr_ruta)->toBeNull()
        ->and(json_encode($envio->diagnostico_consulta))->not->toContain('SECRET');
})->with(['estructura', 'codigo', 'contradictorio', 'cdr', 'cdr_no_definitivo']);

test('native SOAP serializes getStatus to the Beta endpoint without enabling trace or retaining envelopes', function (string $caso) {
    $c = emitirFiscal($this->cobro, $this->actor);
    $config = ConfiguracionFacturacion::firstOrFail();
    $payload = match ($caso) {
        'pendiente' => '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><getStatusResponse xmlns="http://service.sunat.gob.pe"><status><statusCode>98</statusCode></status></getStatusResponse></soap:Body></soap:Envelope>',
        'fault' => '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Server.200</faultcode><faultstring>Failed to establish a backside connection</faultstring></soap:Fault></soap:Body></soap:Envelope>',
        'no_xml' => 'not XML SOL-SECRET',
    };
    $soap = new class(WsdlProvider::getBillPath(), ['trace' => false, 'exceptions' => true]) extends SoapClient
    {
        public string $fixture;

        public ?string $destino = null;

        public ?string $accion = null;

        public function __doRequest(#[SensitiveParameter] string $request, string $location, string $action, int $version, bool $oneWay = false): ?string
        {
            $this->destino = $location;
            $this->accion = $action;
            $dom = new DOMDocument;
            expect($dom->loadXML($request))->toBeTrue();
            $xp = new DOMXPath($dom);
            expect($xp->evaluate('string(//*[local-name()="getStatus"]/*[local-name()="ticket"])'))->toBe('T-NATIVE');

            return $this->fixture; // Deterministic local fixture; no network request.
        }
    };
    $soap->fixture = $payload;
    $transport = Mockery::mock(TransporteSunat::class)->makePartial();
    $soap->setService($transport->endpoint($config));
    $transport->shouldReceive('cliente')->once()->andReturn($soap);
    if ($caso === 'pendiente') {
        expect($transport->consultar($config, $c, 'T-NATIVE')->codigo)->toBe('98');
    } else {
        try {
            $transport->consultar($config, $c, 'T-NATIVE');
            $this->fail('Expected a safe SOAP diagnosis');
        } catch (FalloSunat $error) {
            expect($error->tipo)->toBe($caso === 'fault' ? 'soap_sunat' : 'soap_decodificacion')->and($error->estadoHttp)->toBeNull();
        }
    }
    expect($soap->destino)->toBe('https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService')->and($soap->accion)->toBe('urn:getStatus')
        ->and($soap->__getLastRequest())->toBeNull()->and($soap->__getLastResponse())->toBeNull();
})->with(['pendiente', 'fault', 'no_xml']);

test('ticket query locking and interrupted recovery use consultation dates without changing send timestamps', function () {
    $c = emitirFiscal($this->cobro, $this->actor);
    $transport = $this->mock(TransporteSunat::class);
    $transport->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(ticket: 'T-LOCK'));
    $service = app(EnviarComprobante::class);
    $service->enviar($this->actor, $c->id);
    $envio = $c->envios()->firstOrFail();
    $envio->update(['iniciado_at' => now()->subDay(), 'consultando' => true, 'consulta_iniciada_at' => now()]);
    $original = $envio->refresh();
    $transport->shouldNotReceive('consultar');
    $service->consultar($this->actor, $c->id);
    $service->recuperarInterrumpido($this->actor, $c->id);
    expect($envio->fresh()->consultando)->toBeTrue();
    $envio->update(['consulta_iniciada_at' => now()->subMinutes(3)]);
    $service->recuperarInterrumpido($this->actor, $c->id);
    expect($envio->fresh()->consultando)->toBeFalse()->and($envio->fresh()->iniciado_at->equalTo($original->iniciado_at))->toBeTrue()
        ->and($envio->fresh()->finalizado_at->equalTo($original->finalizado_at))->toBeTrue()->and($envio->fresh()->mensaje)->toBe($original->mensaje)
        ->and($envio->fresh()->ticket)->toBe('T-LOCK')->and($c->fresh()->estado_sunat->value)->toBe('enviado');
});

test('ticket transport only finalizes after a matching validated CDR with a consistent summary status', function (string $estadoConsulta, string $codigoCdr, bool $observaciones, string $esperado) {
    $c = emitirFiscal($this->cobro, $this->actor);
    $this->mock(TransporteSunat::class)->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(ticket: 'T-FINAL'));
    $service = app(EnviarComprobante::class);
    $service->enviar($this->actor, $c->id);
    $envio = $c->envios()->firstOrFail();
    $original = $envio->getAttributes();
    $zip = zipCdrFiscal($c, $envio->xml_nombre, $codigoCdr, observado: $observaciones);
    $soap = Mockery::mock(SoapClient::class);
    $soap->shouldReceive('call')->once()->with('getStatus', ['parameters' => ['ticket' => 'T-FINAL']])->andReturn((object) ['status' => (object) ['statusCode' => $estadoConsulta, 'content' => $zip]]);
    $transport = Mockery::mock(TransporteSunat::class)->makePartial();
    $transport->shouldReceive('cliente')->once()->andReturn($soap);
    app()->instance(TransporteSunat::class, $transport);
    $service->consultar($this->actor, $c->id);
    expect($c->fresh()->estado_sunat->value)->toBe($esperado)->and($envio->fresh()->ticket)->toBe('T-FINAL')
        ->and($envio->fresh()->getRawOriginal('iniciado_at'))->toBe($original['iniciado_at'])->and($envio->fresh()->getRawOriginal('finalizado_at'))->toBe($original['finalizado_at'])
        ->and($envio->fresh()->mensaje)->toContain($original['mensaje'], 'Resultado de consulta:')->and($envio->fresh()->consulta_finalizada_at)->not->toBeNull();
})->with([['0', '0', false, 'aceptado'], ['0', '0', true, 'observado'], ['99', '2335', false, 'rechazado']]);

function respuestaHttpSoapFiscal(string $codigo = '98', ?string $zip = null): string
{
    return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><getStatusResponse xmlns="http://service.sunat.gob.pe"><status><statusCode>'.$codigo.'</statusCode>'.($zip === null ? '' : '<content>'.base64_encode($zip).'</content>').'</status></getStatusResponse></s:Body></s:Envelope>';
}

test('native HTTP SOAP roundtrip constructs safe getStatus headers WSSE and processes simulated responses', function (string $caso, string $esperado, ?string $fallo) {
    $c = emitirFiscal($this->cobro, $this->actor);
    $this->mock(TransporteSunat::class)->shouldReceive('enviar')->once()->andReturn(new RespuestaSunat(ticket: 'T-LOCAL-SOAP'));
    $service = app(EnviarComprobante::class);
    $service->enviar($this->actor, $c->id);
    $envio = $c->envios()->firstOrFail();
    $original = $envio->getAttributes();
    $config = ConfiguracionFacturacion::firstOrFail();
    $config->update(['usuario_sol' => 'LOCALUSER', 'clave_sol' => 'LOCAL-SOAP-SECRET']);
    $zip = zipCdrFiscal($c, $envio->xml_nombre, $caso === 'rechazado' ? '2335' : '0', ruc: $caso === 'ajeno' ? '20999999999' : '20131312955');
    $response = match ($caso) {
        'aceptado', 'rechazado', 'ajeno' => respuestaHttpSoapFiscal($caso === 'rechazado' ? '99' : '0', $zip),
        'soap_fault' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><faultcode>s:Server.200</faultcode><faultstring>Failed to establish a backside connection LOCAL-SOAP-SECRET</faultstring></s:Fault></s:Body></s:Envelope>',
        'http_503', 'no_xml' => 'not XML LOCAL-SOAP-SECRET',
        'http_503_vacio' => '',
        default => respuestaHttpSoapFiscal(),
    };
    $fixture = ['ticket' => 'T-LOCAL-SOAP', 'username' => '20131312955LOCALUSER', 'password' => 'LOCAL-SOAP-SECRET', 'response' => $response,
        'http_status' => $caso === 'soap_fault' ? 500 : (str_starts_with($caso, 'http_503') ? 503 : 200),
        'drop_headers' => $caso === 'sin_cabeceras', 'chunked' => $caso === 'chunked', 'close_delimited' => $caso === 'sin_length'];
    $process = proc_open([PHP_BINARY, base_path('tests/Support/soap-http-fixture.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start local SOAP peer');
    }
    try {
        fwrite($pipes[0], json_encode($fixture, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 8);
        $ready = json_decode(fgets($pipes[1]) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        if (! isset($ready['address']) || ! preg_match('/^127\.0\.0\.1:\d+$/D', $ready['address'])) {
            throw new RuntimeException('Local SOAP peer did not become ready');
        }
        // Only this test adapter replaces the fixed Beta endpoint with a loopback HTTP peer.
        $transport = new class('http://'.$ready['address'].'/billService') extends TransporteSunat
        {
            private ?SoapClient $nativeClient = null;

            public function __construct(private string $localEndpoint) {}

            public function endpoint(#[SensitiveParameter] ConfiguracionFacturacion $config, bool $consultaCdr = false): string
            {
                expect(parent::endpoint($config, $consultaCdr))->toBe('https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService');

                return $this->localEndpoint;
            }

            public function cliente(#[SensitiveParameter] ConfiguracionFacturacion $config, string $ruc, bool $consultaCdr = false): SoapClient
            {
                return $this->nativeClient = parent::cliente($config, $ruc, $consultaCdr);
            }

            public function traceVacio(): bool
            {
                return $this->nativeClient !== null && $this->nativeClient->__getLastRequest() === null && $this->nativeClient->__getLastResponse() === null;
            }
        };
        app()->instance(TransporteSunat::class, $transport);
        Log::spy();
        $service->consultar($this->actor, $c->id);
        $metadata = json_decode(fgets($pipes[1]) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        expect(count($metadata))->toBe(13);
        foreach ($metadata as $name => $valid) {
            expect($valid, 'HTTP contract: '.$name)->toBeTrue(); // Only booleans, no envelope/header values.
        }
        expect($transport->traceVacio())->toBeTrue()->and($c->fresh()->estado_sunat->value)->toBe($esperado)
            ->and($envio->fresh()->ticket)->toBe('T-LOCAL-SOAP')->and($c->envios()->count())->toBe(1)
            ->and($envio->fresh()->getRawOriginal('iniciado_at'))->toBe($original['iniciado_at'])
            ->and($envio->fresh()->getRawOriginal('finalizado_at'))->toBe($original['finalizado_at']);
        if ($fallo !== null) {
            expect($envio->fresh()->diagnostico_consulta['tipo'])->toBe($fallo)->and($c->fresh()->cdr_ruta)->toBeNull();
            Log::shouldHaveReceived('warning')->once()->with('Diagnóstico seguro SUNAT', Mockery::on(fn ($context) => ! str_contains(json_encode($context), 'LOCAL-SOAP-SECRET') && ! str_contains(json_encode($context), 'T-LOCAL-SOAP') && ! str_contains(json_encode($context), '<')));
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0);
        $process = null;
    } finally {
        if (is_resource($process)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            proc_close($process);
        }
    }
})->with([
    ['pendiente', 'enviado', null], ['aceptado', 'aceptado', null], ['rechazado', 'rechazado', null],
    ['chunked', 'enviado', null], ['sin_length', 'enviado', null],
    ['soap_fault', 'enviado', 'soap_sunat'], ['http_503', 'enviado', 'soap_decodificacion'], ['http_503_vacio', 'enviado', 'http_servidor'],
    ['sin_cabeceras', 'enviado', 'http_sin_cabeceras'], ['no_xml', 'enviado', 'soap_decodificacion'], ['ajeno', 'enviado', 'cdr_referencia'],
]);

test('SOAP configuration keeps verified TLS and explicit protocol connection limits without manual headers', function () {
    $transport = new class extends TransporteSunat
    {
        public function opcionesPrueba(): array
        {
            return $this->opcionesCliente();
        }
    };
    $options = $transport->opcionesPrueba();
    $context = stream_context_get_options($options['stream_context']);
    expect($options['trace'])->toBeFalse()->and($options['soap_version'])->toBe(SOAP_1_1)->and($options['exceptions'])->toBeTrue()
        ->and($options['connection_timeout'])->toBe(10)->and($options['keep_alive'])->toBeFalse()->and($options['cache_wsdl'])->toBe(WSDL_CACHE_NONE)
        ->and($context)->toBe(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false], 'http' => ['protocol_version' => 1.1]])
        ->and(array_key_exists('header', $context['http']))->toBeFalse();
    $file = WsdlProvider::getBillPath();
    expect(is_file($file))->toBeTrue()->and(str_contains($file, '://'))->toBeFalse();
    $client = $transport->cliente(ConfiguracionFacturacion::firstOrFail(), '20131312955');
    expect(str_contains(implode('\n', $client->__getFunctions()), 'getStatus('))->toBeTrue();
});

test('native SOAP response timeout is applied per call and restored on success fault or client construction failure', function (string $caso) {
    $c = emitirFiscal($this->cobro, $this->actor);
    $config = ConfiguracionFacturacion::firstOrFail();
    $transport = Mockery::mock(TransporteSunat::class)->makePartial();
    $transport->shouldReceive('cliente')->once()->andReturnUsing(function () use ($caso) {
        expect(ini_get('default_socket_timeout'))->toBe('25');
        if ($caso === 'constructor') {
            throw new RuntimeException('LOCAL-SOAP-SECRET');
        }
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('call')->once()->andReturnUsing(function () use ($caso) {
            expect(ini_get('default_socket_timeout'))->toBe('25');
            if ($caso === 'fault') {
                throw new SoapFault('HTTP', 'Error Fetching http headers');
            }

            return (object) ['status' => (object) ['statusCode' => '98']];
        });

        return $client;
    });
    $previous = ini_get('default_socket_timeout');
    try {
        ini_set('default_socket_timeout', '7');
        try {
            $response = $transport->consultar($config, $c, 'T-SCOPE');
            expect($response->codigo)->toBe('98')->and($caso)->toBe('success');
        } catch (FalloSunat $error) {
            expect($caso)->not->toBe('success')->and($error->tipo)->toBe($caso === 'fault' ? 'http_sin_cabeceras' : 'error_no_clasificado');
        }
        expect(ini_get('default_socket_timeout'))->toBe('7');
    } finally {
        ini_set('default_socket_timeout', $previous);
    }
})->with(['success', 'fault', 'constructor']);
