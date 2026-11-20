<?php

use App\Models\AreaPreparacion;
use App\Models\Articulo;
use App\Models\Caja;
use App\Models\CategoriaProducto;
use App\Models\Cobro;
use App\Models\EnvioCocina;
use App\Models\Impresora;
use App\Models\MedioPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\TrabajoImpresion;
use App\Models\TurnoCaja;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Cobros\GestionCobros;
use App\Services\Configuracion\GestionImpresoras;
use App\Services\Impresion\ImpresionOperativa;
use App\Services\Impresion\TransporteEscPos;
use App\Services\Pos\ConfirmarPedido;
use App\Services\Pos\GestionPedidos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
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
if (($argv[1] ?? '') === 'worker') {
    [$modo,$id,$actorId,$clave,$directorio,$indice] = array_slice($argv, 2);
    $actor = User::findOrFail((int) $actorId);
    $pedido = Pedido::findOrFail((int) $id);
    $turno = TurnoCaja::where('usuario_id', $actor->id)->firstOrFail();
    $app->instance(TransporteEscPos::class, new class($directorio, $indice) extends TransporteEscPos
    {
        public function __construct(private string $directorio, private string $indice) {}

        public function enviar(string $host, int $puerto, string $contenido): void
        {
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('La impresión mantuvo una transacción durante la red.');
            }
            file_put_contents($this->directorio.'/envios', json_encode(['host' => $host, 'puerto' => $puerto, 'contenido' => base64_encode($contenido)], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
            $limite = microtime(true) + 15;
            while (! is_file($this->directorio.'/release')) {
                if (microtime(true) > $limite) {
                    throw new RuntimeException('No se liberó el transporte simulado.');
                }usleep(10000);
            }
        }
    });
    if (in_array($modo, ['original', 'copia', 'precuenta'], true)) {
        $solicitud = ['tipo' => $modo === 'precuenta' ? 'precuenta' : 'comanda', 'id' => $modo === 'precuenta' ? $pedido->id : EnvioCocina::where('pedido_id', $pedido->id)->firstOrFail()->id, 'caja' => $modo === 'precuenta' ? $turno->caja_id : 0, 'area' => 0];
        $copia = $modo === 'copia' ? TrabajoImpresion::where('pedido_id', $pedido->id)->where('tipo', 'comanda')->whereNull('reimpresion_de_id')->firstOrFail()->id : 0;
        $plan = app(ImpresionOperativa::class)->previsualizar($actor, $solicitud, $copia);
    } elseif ($modo === 'cobrar') {
        $detalle = $pedido->detalles()->whereNull('detalle_padre_id')->firstOrFail();
        $datos = ['clienteId' => '', 'grupoPagador' => '', 'incluirEnvio' => false];
        $seleccion = ['uno' => ['detalleId' => (string) $detalle->id, 'cantidad' => '1']];
        $pagos = ['uno' => ['medioId' => (string) MedioPago::where('codigo', $actor->username)->value('id'), 'monto' => '6', 'entregado' => '', 'referencia' => '']];
        $plan = app(GestionCobros::class)->previsualizar($actor, $pedido->id, $turno->id, $datos, $seleccion, $pagos);
    } else {
        $printer = Impresora::where('area_preparacion_id', EnvioCocina::where('pedido_id', $pedido->id)->first()->area_preparacion_id)->firstOrFail();
    }
    file_put_contents($directorio.'/ready-'.$indice, '1');
    $limite = microtime(true) + 15;
    while (! is_file($directorio.'/go')) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('Sin señal de impresión concurrente.');
        }usleep(10000);
    }
    try {
        if (in_array($modo, ['original', 'copia', 'precuenta'], true)) {
            app(ImpresionOperativa::class)->imprimir($actor, $solicitud, $copia, 'Copia de prueba concurrente', $clave, $plan['huella']);
        } elseif ($modo === 'cobrar') {
            app(GestionCobros::class)->cobrar($actor, $pedido->id, $turno->id, $clave, $plan['huella'], $datos, $seleccion, $pagos);
        } else {
            app(GestionImpresoras::class)->cambiarEstado($actor, $printer->id, false);
        }
        exit(0);
    } catch (ValidationException $e) {
        fwrite(STDERR, implode(' ', $e->validator->errors()->all()));
        exit(2);
    }
}

/**
 * @param  array<int,array{0:string,1:int,2:string}>  $operaciones
 * @return array{codigos:array<int,int>,envios:int}
 */
function competirImpresion(array $operaciones, int $actorId, string $directorio): array
{
    mkdir($directorio, 0700, true);
    $procesos = [];
    try {
        foreach ($operaciones as $indice => [$modo, $id, $clave]) {
            $proceso = proc_open([PHP_BINARY, __FILE__, 'worker', $modo, (string) $id, (string) $actorId, $clave, $directorio, (string) $indice], [0 => ['pipe', 'r'], 1 => ['file', $directorio.'/out-'.$indice, 'w'], 2 => ['file', $directorio.'/err-'.$indice, 'w']], $pipes, dirname(__DIR__, 2));
            if (! is_resource($proceso)) {
                throw new RuntimeException('No se pudo iniciar una petición concurrente.');
            }
            fclose($pipes[0]);
            $procesos[$indice] = $proceso;
        }
        $limite = microtime(true) + 15;
        while (count(glob($directorio.'/ready-*')) !== count($procesos)) {
            if (microtime(true) > $limite) {
                throw new RuntimeException('Las peticiones no quedaron listas.');
            }
            usleep(10000);
        }
        file_put_contents($directorio.'/go', '1');
        $codigos = [];
        $limite = microtime(true) + 20;
        while (count($codigos) < count($procesos)) {
            foreach ($procesos as $indice => $proceso) {
                if (! array_key_exists($indice, $codigos)) {
                    $estado = proc_get_status($proceso);
                    if (! $estado['running']) {
                        $codigos[$indice] = $estado['exitcode'];
                        if (! in_array($estado['exitcode'], [0, 2], true)) {
                            throw new RuntimeException(file_get_contents($directorio.'/err-'.$indice));
                        }
                    }
                }
            }
            if ($codigos !== []) {
                file_put_contents($directorio.'/release', '1');
            }
            if (microtime(true) > $limite) {
                throw new RuntimeException('La prueba de cobros excedió el tiempo permitido.');
            }
            usleep(10000);
        }
        sort($codigos);

        return ['codigos' => $codigos, 'envios' => is_file($directorio.'/envios') ? count(file($directorio.'/envios')) : 0];
    } finally {
        foreach ($procesos as $proceso) {
            if (proc_get_status($proceso)['running']) {
                proc_terminate($proceso);
            }
            proc_close($proceso);
        }
        foreach (glob($directorio.'/*') as $archivo) {
            unlink($archivo);
        }
        rmdir($directorio);
    }
}

$tag = substr(str_replace('-', '', (string) Str::uuid()), 0, 8);
$actor = $area = $unidad = $categoria = $articulo = $producto = $caja = $turno = $printer = $cashPrinter = $medio = null;
$pedidos = [];
try {
    $actor = User::create(['name' => 'Prueba impresión', 'username' => 'prt_'.$tag, 'password' => Str::random(40), 'activo' => true]);
    foreach (['pos.ver', 'pedidos.ver_todos', 'pedidos.crear', 'pedidos.editar', 'pedidos.enviar', 'cobros.crear', 'cajas.ver_propias', 'impresion.comandas', 'impresion.precuentas', 'impresion.reimprimir', 'impresoras.gestionar'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
        $actor->givePermissionTo($permiso);
    }
    $medio = MedioPago::create(['codigo' => $actor->username, 'nombre' => 'Efectivo prueba', 'afecta_efectivo' => true, 'activo' => true]);
    $unidad = UnidadMedida::create(['codigo' => 'u-'.$tag, 'nombre' => 'Unidad prueba', 'magnitud' => 'unidad', 'factor_base' => 1]);
    $categoria = CategoriaProducto::create(['nombre' => 'Impresión '.$tag, 'activo' => true]);
    $area = AreaPreparacion::create(['codigo' => 'prt-'.$tag, 'nombre' => 'Destino impresión']);
    $printer = Impresora::create(['codigo' => 'pr-'.$tag, 'nombre' => 'Impresora prueba', 'host' => '127.0.0.1', 'puerto' => 19111, 'activo' => true, 'area_preparacion_id' => $area->id]);
    $cashPrinter = Impresora::create(['codigo' => 'pc-'.$tag, 'nombre' => 'Caja prueba', 'host' => '127.0.0.1', 'puerto' => 19112, 'activo' => true]);
    $articulo = Articulo::create(['codigo' => 'PRT-'.$tag, 'nombre' => 'Existencia impresión', 'tipo' => 'preparado', 'unidad_medida_id' => $unidad->id, 'stock_actual' => '20']);
    $producto = Producto::create(['codigo' => 'PRT-'.$tag, 'nombre' => 'Plato impresión', 'tipo' => 'plato', 'articulo_id' => $articulo->id, 'categoria_producto_id' => $categoria->id, 'area_preparacion_id' => $area->id, 'precio_venta' => '6', 'activo' => true, 'visible_pos' => true]);
    $caja = Caja::create(['codigo' => 'pr-'.$tag, 'nombre' => 'Caja impresión', 'impresora_id' => $cashPrinter->id]);
    $turno = TurnoCaja::create(['caja_id' => $caja->id, 'usuario_id' => $actor->id, 'abierta_at' => now(), 'fondo_inicial' => '100']);
    for ($i = 0; $i < 4; $i++) {
        $pedido = app(GestionPedidos::class)->abrir($actor, $turno->id, (string) Str::uuid(), ['modalidad' => 'rapida', 'mesaId' => '', 'mozoId' => (string) $actor->id, 'comensales' => '', 'observaciones' => '', 'destinatario' => '', 'telefono' => '', 'direccion' => '', 'referencia' => '', 'costoEnvio' => '0'])->refresh();
        $pedidos[] = $pedido->id;
        app(GestionPedidos::class)->guardar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido), ['clienteId' => '', 'comensales' => '', 'observaciones' => ''], ['uno' => ['detalleId' => '', 'productoId' => (string) $producto->id, 'cantidad' => '1', 'nota' => 'No duplicar preparación']]);
        app(ConfirmarPedido::class)->confirmar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido->fresh()), (string) Str::uuid());
    }
    $dir = storage_path('framework/testing/impresion-'.$tag);
    $r = competirImpresion([['original', $pedidos[0], (string) Str::uuid()], ['original', $pedidos[0], (string) Str::uuid()]], $actor->id, $dir);
    if ($r !== ['codigos' => [0, 0], 'envios' => 1]) {
        throw new RuntimeException('Dos solicitudes del mismo documento lo enviaron más de una vez.');
    }
    $clave = (string) Str::uuid();
    $r = competirImpresion([['original', $pedidos[1], $clave], ['original', $pedidos[1], $clave]], $actor->id, $dir);
    if ($r !== ['codigos' => [0, 0], 'envios' => 1]) {
        throw new RuntimeException('El UUID repetido duplicó la impresión.');
    }
    $clave = (string) Str::uuid();
    $r = competirImpresion([['copia', $pedidos[0], $clave], ['copia', $pedidos[0], $clave]], $actor->id, $dir);
    if ($r !== ['codigos' => [0, 0], 'envios' => 1] || TrabajoImpresion::where('pedido_id', $pedidos[0])->count() !== 2) {
        throw new RuntimeException('La copia repetida se imprimió dos veces.');
    }
    $r = competirImpresion([['original', $pedidos[2], (string) Str::uuid()], ['editar', $pedidos[2], '']], $actor->id, $dir);
    if ($r['codigos'] !== [0, 2] || $r['envios'] > 1) {
        throw new RuntimeException('La configuración cambió durante un envío o se aceptó una revisión obsoleta.');
    }
    $printer->update(['activo' => true]);
    $r = competirImpresion([['precuenta', $pedidos[3], (string) Str::uuid()], ['cobrar', $pedidos[3], (string) Str::uuid()]], $actor->id, $dir);
    if (! in_array($r['codigos'], [[0, 0], [0, 2]], true) || $r['envios'] > 1 || Cobro::where('pedido_id', $pedidos[3])->count() !== 1) {
        throw new RuntimeException('Precuenta y cobro no conservaron sus versiones.');
    }
    foreach (TrabajoImpresion::where('pedido_id', $pedidos[3])->get() as $p) {
        if (($p->contenido['totales'][3]['monto'] ?? null) !== '6.00') {
            throw new RuntimeException('Se imprimió un saldo que no fue revisado.');
        }
    }
    if ($articulo->fresh()->stock_actual !== '20.0000' || $articulo->fresh()->stock_reservado !== '4.0000' || $turno->efectivoEsperado() !== '106.00') {
        throw new RuntimeException('La impresión alteró el inventario o el efectivo.');
    }
    echo json_encode(['result' => 'passed', 'checks' => ['same original document', 'duplicate print UUID', 'duplicate copy UUID', 'printer edit versus send', 'prebill versus collection', 'network outside transaction']], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
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
