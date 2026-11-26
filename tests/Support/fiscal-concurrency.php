<?php

use App\Models\AreaPreparacion;
use App\Models\Articulo;
use App\Models\Caja;
use App\Models\CategoriaProducto;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ComprobanteEnvio;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\Impresora;
use App\Models\MedioPago;
use App\Models\Producto;
use App\Models\ResumenDiario;
use App\Models\SerieComprobante;
use App\Models\TipoComprobante;
use App\Models\TurnoCaja;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Cobros\GestionCobros;
use App\Services\Facturacion\ConfigurarFacturacion;
use App\Services\Facturacion\EmitirComprobante;
use App\Services\Facturacion\EnviarComprobante;
use App\Services\Facturacion\GestionResumenes;
use App\Services\Facturacion\RespuestaSunat;
use App\Services\Facturacion\TransporteSunat;
use App\Services\Pos\ConfirmarPedido;
use App\Services\Pos\GestionPedidos;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\ConfiguracionInicialSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'sisrest_test') {
    throw new RuntimeException('Usa APP_ENV=testing y la base MySQL desechable sisrest_test.');
}
if (! Empresa::exists()) {
    (new CatalogosSeeder)->run();
    (new ConfiguracionInicialSeeder)->run();
}

if (($argv[1] ?? '') === 'worker') {
    [$modo,$id,$actorId,$serieId,$clave,$directorio,$indice] = array_slice($argv, 2);
    $actor = User::findOrFail((int) $actorId);
    $version = ConfigurarFacturacion::version(Empresa::firstOrFail(), ConfiguracionFacturacion::firstOrFail());
    $app->instance(TransporteSunat::class, new class($directorio) extends TransporteSunat
    {
        public function __construct(private string $directorio) {}

        public function enviar(ConfiguracionFacturacion $config, string $ruc, string $tipo, string $nombre, string $xml): RespuestaSunat
        {
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('SUNAT se llamó dentro de una transacción.');
            }
            file_put_contents($this->directorio.'/requests', json_encode(['nombre' => $nombre]).PHP_EOL, FILE_APPEND | LOCK_EX);
            $limite = microtime(true) + 15;
            while (! is_file($this->directorio.'/release')) {
                if (microtime(true) > $limite) {
                    throw new RuntimeException('Transporte sin liberar.');
                }usleep(10000);
            }

            return new RespuestaSunat(ticket: 'T-'.basename($nombre));
        }

        public function consultarTicket(ConfiguracionFacturacion $config, string $ruc, string $ticket, string $entorno): RespuestaSunat
        {
            $this->enviar($config, $ruc, '03', 'consulta', '');

            return new RespuestaSunat(pendiente: true, codigo: '98');
        }
    });
    file_put_contents($directorio.'/ready-'.$indice, '1');
    $limite = microtime(true) + 15;
    while (! is_file($directorio.'/go')) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('Barrera sin liberar.');
        }usleep(10000);
    }
    if ($modo === 'emitir') {
        $cobro = Cobro::findOrFail((int) $id);
        $filas = $cobro->detalles()->pluck('id')->mapWithKeys(fn ($id) => [$id => '10'])->all();
        $c = app(EmitirComprobante::class)->emitir($actor, $cobro->id, $clave, ['serieId' => (int) $serieId, 'tipo' => '0', 'numero' => '', 'nombre' => 'Cliente concurrente', 'direccion' => ''], $filas, $version);
        echo $c->id.PHP_EOL;
    } elseif (in_array($modo, ['resumen_generar', 'resumen_conflicto'], true)) {
        try {
            $r = app(GestionResumenes::class)->generar($actor, [(int) $id], $clave);
            echo $r->id.PHP_EOL;
        } catch (ValidationException $error) {
            if ($modo !== 'resumen_conflicto') {
                throw $error;
            }
            echo "reservation conflict safely blocked\n";
        }
    } elseif ($modo === 'resumen_enviar') {
        app(GestionResumenes::class)->enviar($actor, (int) $id);
    } elseif ($modo === 'resumen_consultar') {
        app(GestionResumenes::class)->consultar($actor, (int) $id);
    } else {
        app(EnviarComprobante::class)->enviar($actor, (int) $id);
    }
    exit(0);
}

function competirFiscal(string $modo, array $ids, int $actor, int $serie, string $directorio, bool $mismoUuid = false): int
{
    if (! is_dir($directorio)) {
        mkdir($directorio, 0777, true);
    }
    $procesos = [];
    $codigos = [];
    $clave = (string) Str::uuid();
    try {
        foreach ($ids as $i => $id) {
            $pipes = [];
            $p = proc_open([PHP_BINARY, __FILE__, 'worker', $modo, (string) $id, (string) $actor, (string) $serie, $mismoUuid ? $clave : (string) Str::uuid(), $directorio, (string) $i], [0 => ['pipe', 'r'], 1 => ['file', $directorio.'/out-'.$i, 'w'], 2 => ['file', $directorio.'/err-'.$i, 'w']], $pipes, dirname(__DIR__, 2));
            if (! is_resource($p)) {
                throw new RuntimeException('No se pudo crear un proceso.');
            }fclose($pipes[0]);
            $procesos[$i] = $p;
        }
        $limite = microtime(true) + 20;
        while (count(glob($directorio.'/ready-*')) !== count($ids)) {
            if (microtime(true) > $limite) {
                throw new RuntimeException('Procesos no preparados.');
            }usleep(10000);
        }
        file_put_contents($directorio.'/go', '1');
        while (count($codigos) < count($ids)) {
            foreach ($procesos as $i => $p) {
                if (! array_key_exists($i, $codigos)) {
                    $s = proc_get_status($p);
                    if (! $s['running']) {
                        $codigos[$i] = $s['exitcode'];
                        if ($s['exitcode'] !== 0) {
                            throw new RuntimeException(file_get_contents($directorio.'/err-'.$i));
                        }
                    }
                }
            }
            $peticiones = is_file($directorio.'/requests') ? count(file($directorio.'/requests', FILE_IGNORE_NEW_LINES)) : 0;
            if (in_array($modo, ['enviar', 'resumen_enviar', 'resumen_consultar'], true) && ($peticiones >= 2 || ($peticiones === 1 && count($codigos) > 0))) {
                file_put_contents($directorio.'/release', '1');
            }
            if (microtime(true) > $limite) {
                throw new RuntimeException('La concurrencia fiscal excedió el plazo.');
            }usleep(10000);
        }

        return is_file($directorio.'/requests') ? count(file($directorio.'/requests', FILE_IGNORE_NEW_LINES)) : 0;
    } finally {
        foreach ($procesos as $p) {
            if (proc_get_status($p)['running']) {
                proc_terminate($p);
            }proc_close($p);
        }
        foreach (glob($directorio.'/*') as $file) {
            unlink($file);
        }rmdir($directorio);
    }
}
$tag = substr(str_replace('-', '', (string) Str::uuid()), 0, 8);
$actor = $area = $unidad = $categoria = $articulo = $producto = $caja = $turno = $printer = $cashPrinter = $medio = null;
$pedidos = [];
$empresaAntes = Empresa::firstOrFail()->getRawOriginal();
$configAntes = ConfiguracionFacturacion::firstOrFail()->getRawOriginal();
$serieFiscal = null;
$secuenciaAntes = DB::table('secuencias_resumen')->where('fecha', now('America/Lima')->toDateString())->where('entorno', 'beta')->first();
$activityInicio = DB::table('activity_log')->max('id') ?? 0;
$rutaCert = 'testing/fiscal-concurrency-'.$tag.'.enc';
try {
    $actor = User::create(['name' => 'Prueba impresión', 'username' => 'fsc_'.$tag, 'password' => Str::random(40), 'activo' => true]);
    foreach (['pos.ver', 'pedidos.ver_todos', 'pedidos.crear', 'pedidos.editar', 'pedidos.enviar', 'cobros.crear', 'cajas.ver_propias', 'impresion.comandas', 'impresion.precuentas', 'impresion.reimprimir', 'impresoras.gestionar', 'comprobantes.ver', 'comprobantes.emitir', 'comprobantes.reenviar'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
        $actor->givePermissionTo($permiso);
    }
    $medio = MedioPago::create(['codigo' => $actor->username, 'nombre' => 'Efectivo prueba', 'afecta_efectivo' => true, 'activo' => true]);
    $unidad = UnidadMedida::create(['codigo' => 'u-'.$tag, 'nombre' => 'Unidad prueba', 'magnitud' => 'unidad', 'factor_base' => 1]);
    $categoria = CategoriaProducto::create(['nombre' => 'Impresión '.$tag, 'activo' => true]);
    $area = AreaPreparacion::create(['codigo' => 'prt-'.$tag, 'nombre' => 'Destino impresión']);
    $printer = Impresora::create(['codigo' => 'pr-'.$tag, 'nombre' => 'Impresora prueba', 'host' => '127.0.0.1', 'puerto' => 19111, 'activo' => true, 'area_preparacion_id' => $area->id]);
    $cashPrinter = Impresora::create(['codigo' => 'pc-'.$tag, 'nombre' => 'Caja prueba', 'host' => '127.0.0.1', 'puerto' => 19112, 'activo' => true]);
    $articulo = Articulo::create(['codigo' => 'FSC-'.$tag, 'nombre' => 'Existencia impresión', 'tipo' => 'preparado', 'unidad_medida_id' => $unidad->id, 'stock_actual' => '20']);
    $producto = Producto::create(['codigo' => 'FSC-'.$tag, 'nombre' => 'Plato impresión', 'tipo' => 'plato', 'articulo_id' => $articulo->id, 'categoria_producto_id' => $categoria->id, 'area_preparacion_id' => $area->id, 'precio_venta' => '6', 'activo' => true, 'visible_pos' => true]);
    $caja = Caja::create(['codigo' => 'pr-'.$tag, 'nombre' => 'Caja impresión', 'impresora_id' => $cashPrinter->id]);
    $turno = TurnoCaja::create(['caja_id' => $caja->id, 'usuario_id' => $actor->id, 'abierta_at' => now(), 'fondo_inicial' => '100']);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'SisRest CONCURRENCY TEST ONLY'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_pkcs12_export($cert, $pfx, $key, 'test');
    Storage::disk('local')->put($rutaCert, Crypt::encryptString($pfx));
    Empresa::first()->update(['ruc' => '20131312955', 'razon_social' => 'TEST CONCURRENCY', 'direccion' => 'TEST', 'ubigeo' => '150101', 'departamento' => 'LIMA', 'provincia' => 'LIMA', 'distrito' => 'LIMA']);
    ConfiguracionFacturacion::first()->update(['habilitada' => true, 'entorno' => 'beta', 'usuario_sol' => 'TEST', 'clave_sol' => 'TEST', 'clave_certificado' => 'test', 'certificado_ruta' => $rutaCert, 'tasa_impuesto' => '18', 'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => null]);
    do {
        $numeroSerie = 'B'.strtoupper(substr(Str::random(8), 0, 3));
    } while (SerieComprobante::where('serie', $numeroSerie)->exists());
    $serieFiscal = SerieComprobante::create(['tipo_comprobante_id' => TipoComprobante::where('codigo', '03')->firstOrFail()->id, 'serie' => $numeroSerie, 'entorno' => 'beta', 'activo' => true]);
    $cobrosFiscales = [];
    for ($i = 0; $i < 7; $i++) {
        $pedido = app(GestionPedidos::class)->abrir($actor, $turno->id, (string) Str::uuid(), ['modalidad' => 'rapida', 'mesaId' => '', 'mozoId' => (string) $actor->id, 'comensales' => '', 'observaciones' => '', 'destinatario' => '', 'telefono' => '', 'direccion' => '', 'referencia' => '', 'costoEnvio' => '0'])->refresh();
        $pedidos[] = $pedido->id;
        app(GestionPedidos::class)->guardar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido), ['clienteId' => '', 'comensales' => '', 'observaciones' => ''], ['uno' => ['detalleId' => '', 'productoId' => (string) $producto->id, 'cantidad' => '1', 'nota' => '']]);
        app(ConfirmarPedido::class)->confirmar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido->fresh()), (string) Str::uuid());
        $detalle = $pedido->detalles()->whereNull('detalle_padre_id')->firstOrFail();
        $datos = ['clienteId' => '', 'grupoPagador' => '', 'incluirEnvio' => false];
        $seleccion = ['d1' => ['detalleId' => (string) $detalle->id, 'cantidad' => '1']];
        $pagos = ['p1' => ['medioId' => (string) $medio->id, 'monto' => '6', 'entregado' => '6', 'referencia' => '']];
        $plan = app(GestionCobros::class)->previsualizar($actor, $pedido->id, $turno->id, $datos, $seleccion, $pagos);
        $cobrosFiscales[] = app(GestionCobros::class)->cobrar($actor, $pedido->id, $turno->id, (string) Str::uuid(), $plan['huella'], $datos, $seleccion, $pagos)->id;
    }
    $dir = storage_path('framework/testing/fiscal-'.$tag);
    competirFiscal('emitir', [$cobrosFiscales[0], $cobrosFiscales[0]], $actor->id, $serieFiscal->id, $dir);
    competirFiscal('emitir', [$cobrosFiscales[1], $cobrosFiscales[2]], $actor->id, $serieFiscal->id, $dir);
    competirFiscal('emitir', [$cobrosFiscales[3], $cobrosFiscales[3]], $actor->id, $serieFiscal->id, $dir, true);
    $docs = Comprobante::whereIn('cobro_id', array_slice($cobrosFiscales, 0, 4))->orderBy('correlativo')->get();
    if ($docs->count() !== 4 || $docs->pluck('correlativo')->all() !== [1, 2, 3, 4] || $serieFiscal->fresh()->ultimo_correlativo !== 4) {
        throw new RuntimeException('Se duplicó un cobro/correlativo o se perdió una asignación.');
    }
    $enviados = competirFiscal('enviar', [$docs[0]->id, $docs[0]->id], $actor->id, $serieFiscal->id, $dir);
    if ($enviados !== 1 || $docs[0]->envios()->count() !== 1 || $docs[0]->fresh()->estado_sunat->value !== 'enviado') {
        throw new RuntimeException('El resumen del mismo documento se envió más de una vez o falló el transporte.');
    }
    $enviados = competirFiscal('enviar', [$docs[1]->id, $docs[2]->id], $actor->id, $serieFiscal->id, $dir);
    if ($enviados !== 2 || ComprobanteEnvio::whereIn('comprobante_id', $docs->pluck('id'))->pluck('xml_nombre')->unique()->count() !== 3) {
        throw new RuntimeException('Se duplicó un resumen diario.');
    }
    competirFiscal('emitir', array_slice($cobrosFiscales, 4, 3), $actor->id, $serieFiscal->id, $dir);
    $extras = Comprobante::whereIn('cobro_id', array_slice($cobrosFiscales, 4, 3))->orderBy('id')->get();
    competirFiscal('resumen_generar', [$docs[3]->id, $docs[3]->id], $actor->id, $serieFiscal->id, $dir, true);
    $reserva = $docs[3]->resumenActivo()->firstOrFail();
    if (ResumenDiario::whereKey($reserva->resumen_diario_id)->count() !== 1 || $docs[3]->resumenesDetalles()->count() !== 1) {
        throw new RuntimeException('La generación idempotente duplicó un resumen o una reserva.');
    }
    competirFiscal('resumen_generar', [$extras[0]->id, $extras[1]->id], $actor->id, $serieFiscal->id, $dir);
    competirFiscal('resumen_conflicto', [$extras[2]->id, $extras[2]->id], $actor->id, $serieFiscal->id, $dir);
    if ($extras[2]->resumenesDetalles()->count() !== 1 || ResumenDiario::where('usuario_id', $actor->id)->pluck('nombre')->unique()->count() !== 7) {
        throw new RuntimeException('Se duplicó una reserva o la secuencia concurrente de resúmenes.');
    }
    $r = ResumenDiario::findOrFail($reserva->resumen_diario_id);
    if (competirFiscal('resumen_enviar', [$r->id, $r->id], $actor->id, $serieFiscal->id, $dir) !== 1 || $r->fresh()->estado !== 'enviado') {
        throw new RuntimeException('El resumen agrupado se envió repetidamente.');
    }
    if (competirFiscal('resumen_consultar', [$r->id, $r->id], $actor->id, $serieFiscal->id, $dir) !== 1 || $r->fresh()->estado !== 'enviado' || $r->eventos()->where('operacion', 'consulta_pendiente')->count() !== 1) {
        throw new RuntimeException('La consulta concurrente duplicó operaciones o perdió el estado pendiente.');
    }
    echo json_encode(['result' => 'passed', 'checks' => ['same charge and different UUIDs', 'same charge and repeated UUID', 'shared series numbering', 'same summary sent once', 'unique concurrent summary numbers', 'SUNAT outside database transactions', 'grouped RC generation idempotency', 'different RC sequences and competing memberships', 'grouped RC sent once', 'ticket consultation claimed once']], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $idsCobro = DB::table('cobros')->whereIn('pedido_id', $pedidos)->pluck('id');
    $docs = Comprobante::whereIn('cobro_id', $idsCobro)->get();
    $resumenes = ResumenDiario::whereHas('detalles', fn ($q) => $q->whereIn('comprobante_id', $docs->pluck('id')))->get();
    foreach ($resumenes as $r) {
        Storage::disk('local')->deleteDirectory('resumenes/'.$r->entorno.'/'.$r->clave_idempotencia);
    }
    DB::table('resumen_diario_eventos')->whereIn('resumen_diario_id', $resumenes->pluck('id'))->delete();
    DB::table('resumen_diario_detalles')->whereIn('resumen_diario_id', $resumenes->pluck('id'))->delete();
    DB::table('resumenes_diarios')->whereIn('id', $resumenes->pluck('id'))->delete();
    foreach ($docs as $doc) {
        Storage::disk('local')->deleteDirectory('comprobantes/beta/'.$doc->clave_idempotencia);
    }
    DB::table('comprobante_envios')->whereIn('comprobante_id', $docs->pluck('id'))->delete();
    DB::table('comprobante_detalles')->whereIn('comprobante_id', $docs->pluck('id'))->delete();
    DB::table('comprobantes')->whereIn('id', $docs->pluck('id'))->delete();
    if ($serieFiscal) {
        DB::table('series_comprobante')->where('id', $serieFiscal->id)->delete();
    }
    DB::table('empresa')->where('id', $empresaAntes['id'])->update($empresaAntes);
    DB::table('configuraciones_facturacion')->where('id', $configAntes['id'])->update($configAntes);
    DB::table('activity_log')->where('subject_type', ConfiguracionFacturacion::class)->where('subject_id', $configAntes['id'])->where('id', '>', $activityInicio)->delete();
    $filtro = DB::table('secuencias_resumen')->where('fecha', now('America/Lima')->toDateString())->where('entorno', 'beta');
    if ($secuenciaAntes) {
        $filtro->update(['correlativo' => $secuenciaAntes->correlativo]);
    } else {
        $filtro->delete();
    }
    Storage::disk('local')->delete($rutaCert);

    $jobs = DB::table('trabajos_impresion')->whereIn('pedido_id', $pedidos);
    (clone $jobs)->whereNotNull('reimpresion_de_id')->delete();
    $jobs->delete();
    $detalles = DB::table('pedido_detalles')->whereIn('pedido_id', $pedidos)->pluck('id');
    $envios = DB::table('envios_cocina')->whereIn('pedido_id', $pedidos)->pluck('id');
    $cobros = DB::table('cobros')->whereIn('pedido_id', $pedidos)->pluck('id');
    DB::table('pagos')->whereIn('cobro_id', $cobros)->delete();
    DB::table('cobro_detalles')->whereIn('cobro_id', $cobros)->delete();
    DB::table('cobros')->whereIn('id', $cobros)->delete();
    DB::table('envio_cocina_detalles')->whereIn('envio_cocina_id', $envios)->delete();
    DB::table('envios_cocina')->whereIn('id', $envios)->delete();
    DB::table('reservas_inventario')->whereIn('pedido_detalle_id', $detalles)->delete();
    DB::table('pedido_detalles')->whereIn('id', $detalles)->delete();
    DB::table('confirmaciones_pedido')->whereIn('pedido_id', $pedidos)->delete();
    DB::table('pedidos')->whereIn('id', $pedidos)->delete();
    if ($turno) {
        DB::table('movimientos_caja')->where('turno_caja_id', $turno->id)->delete();
    }
    foreach (['turnos_caja' => $turno, 'cajas' => $caja, 'productos' => $producto, 'articulos' => $articulo, 'impresoras' => $printer, 'areas_preparacion' => $area, 'categorias_producto' => $categoria, 'unidades_medida' => $unidad, 'medios_pago' => $medio] as $tabla => $modelo) {
        if ($modelo) {
            DB::table($tabla)->where('id', $modelo->id)->delete();
        }
    }
    if ($cashPrinter) {
        DB::table('impresoras')->where('id', $cashPrinter->id)->delete();
    }
    if ($actor) {
        DB::table('activity_log')->where('causer_type', User::class)->where('causer_id', $actor->id)->delete();
        foreach ([Articulo::class => $articulo, Producto::class => $producto, User::class => $actor] as $tipo => $modelo) {
            if ($modelo) {
                DB::table('activity_log')->where('subject_type', $tipo)->where('subject_id', $modelo->id)->delete();
            }
        }DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $actor->id)->delete();
        DB::table('users')->where('id', $actor->id)->delete();
    }
}
