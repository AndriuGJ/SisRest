<?php

use App\Models\AnulacionPedido;
use App\Models\AreaPreparacion;
use App\Models\Articulo;
use App\Models\BeneficioPedido;
use App\Models\Caja;
use App\Models\CategoriaProducto;
use App\Models\EnvioCocinaDetalle;
use App\Models\MedioPago;
use App\Models\MotivoBeneficio;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\TurnoCaja;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Cobros\GestionCobros;
use App\Services\Cocina\GestionCocina;
use App\Services\Pos\ConfirmarPedido;
use App\Services\Pos\GestionAnulaciones;
use App\Services\Pos\GestionBeneficios;
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
require_once __DIR__.'/AutorizacionAnulacion.php';
autorizacionAnulacionPrueba('Anulación de prueba concurrente');

if (($argv[1] ?? '') === 'worker') {
    [$modo,$id,$actorId,$clave,$directorio,$indice] = array_slice($argv, 2);
    $actor = User::findOrFail((int) $actorId);
    $pedido = Pedido::findOrFail((int) $id);
    $linea = $pedido->detalles()->whereNull('detalle_padre_id')->firstOrFail();
    $turno = TurnoCaja::where('usuario_id', $actor->id)->firstOrFail();
    $datos = ['accion' => 'descuento', 'tipo' => 'soles', 'valor' => '1', 'motivoId' => (string) MotivoBeneficio::where('codigo', 'ajuste-race')->value('id'), 'motivo' => 'Promoción de prueba concurrente'];
    if ($modo === 'anular') {
        $plan = app(GestionAnulaciones::class)->previsualizar($actor, $pedido->id, $linea->id, (string) (int) $linea->cantidad, 'Anulación de prueba concurrente');
    } elseif ($modo === 'beneficio') {
        $plan = app(GestionBeneficios::class)->previsualizar($actor, $pedido->id, $linea->id, $datos);
    } elseif ($modo === 'cobrar') {
        $seleccion = ['uno' => ['detalleId' => (string) $linea->id, 'cantidad' => '1']];
        $pagos = ['uno' => ['medioId' => (string) MedioPago::where('codigo', 'ajuste-cash')->value('id'), 'monto' => '6', 'entregado' => '', 'referencia' => '']];
        $datosCobro = ['clienteId' => '', 'grupoPagador' => '', 'incluirEnvio' => false];
        $plan = app(GestionCobros::class)->previsualizar($actor, $pedido->id, $turno->id, $datosCobro, $seleccion, $pagos);
    } else {
        $envio = EnvioCocinaDetalle::where('pedido_detalle_id', $linea->id)->firstOrFail();
        $version = GestionCocina::huella($envio);
    }
    file_put_contents($directorio.'/ready-'.$indice, '1');
    $limite = microtime(true) + 15;
    while (! is_file($directorio.'/go')) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('No llegó la señal de ajustes.');
        }usleep(10000);
    }
    try {
        if ($modo === 'anular') {
            app(GestionAnulaciones::class)->anular($actor, $pedido->id, $linea->id, (string) (int) $linea->cantidad, 'Anulación de prueba concurrente', $clave, $plan['huella'], ...autorizacionAnulacionPrueba('Anulación de prueba concurrente'));
        } elseif ($modo === 'beneficio') {
            app(GestionBeneficios::class)->aplicar($actor, $pedido->id, $linea->id, $clave, $plan['huella'], $datos);
        } elseif ($modo === 'cobrar') {
            app(GestionCobros::class)->cobrar($actor, $pedido->id, $turno->id, $clave, $plan['huella'], $datosCobro, $seleccion, $pagos);
        } else {
            app(GestionCocina::class)->avanzar($actor, $envio->id, 'iniciar', '1', $version);
        }exit(0);
    } catch (ValidationException $e) {
        fwrite(STDERR, implode(' ', $e->validator->errors()->all()));
        exit(2);
    }
}

/**
 * @param  array<int,array{0:string,1:int,2:string}>  $operaciones
 * @return array<int,int>
 */
function competirAjustes(array $operaciones, int $actorId, string $directorio): array
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
            if (microtime(true) > $limite) {
                throw new RuntimeException('La prueba de cobros excedió el tiempo permitido.');
            }
            usleep(10000);
        }
        sort($codigos);

        return $codigos;
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
$actor = $area = $unidad = $categoria = $articulo = $producto = $caja = $turno = $motivo = $medio = null;
$pedidos = [];
try {
    $actor = User::create(['name' => 'Prueba ajustes', 'username' => 'aj_'.$tag, 'password' => Str::random(40), 'activo' => true]);
    foreach (['pos.ver', 'pedidos.ver_todos', 'pedidos.crear', 'pedidos.editar', 'pedidos.enviar', 'pedidos.anular_enviados', 'inventario.registrar_merma', 'cocina.ver', 'cocina.preparar', 'descuentos.aplicar', 'cortesias.aplicar', 'cobros.crear', 'cajas.ver_propias'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
        $actor->givePermissionTo($permiso);
    }
    $motivo = MotivoBeneficio::create(['codigo' => 'ajuste-race', 'nombre' => 'Motivo prueba', 'tipo' => 'ambos', 'activo' => true]);
    $medio = MedioPago::create(['codigo' => 'ajuste-cash', 'nombre' => 'Efectivo prueba', 'afecta_efectivo' => true, 'activo' => true]);
    $unidad = UnidadMedida::create(['codigo' => 'u-'.$tag, 'nombre' => 'Unidad prueba', 'magnitud' => 'unidad', 'factor_base' => 1]);
    $categoria = CategoriaProducto::create(['nombre' => 'Ajuste '.$tag, 'activo' => true]);
    $area = AreaPreparacion::create(['codigo' => 'aj-'.$tag, 'nombre' => 'Destino prueba']);
    $articulo = Articulo::create(['codigo' => 'AJ-'.$tag, 'nombre' => 'Existencia ajustes', 'tipo' => 'preparado', 'unidad_medida_id' => $unidad->id, 'stock_actual' => '20']);
    $producto = Producto::create(['codigo' => 'AJ-'.$tag, 'nombre' => 'Producto ajustes', 'tipo' => 'plato', 'articulo_id' => $articulo->id, 'categoria_producto_id' => $categoria->id, 'area_preparacion_id' => $area->id, 'precio_venta' => '6', 'activo' => true, 'visible_pos' => true]);
    $caja = Caja::create(['codigo' => 'aj-'.$tag, 'nombre' => 'Caja ajustes']);
    $turno = TurnoCaja::create(['caja_id' => $caja->id, 'usuario_id' => $actor->id, 'abierta_at' => now()]);
    foreach (['2', '1', '1', '1', '1'] as $cantidad) {
        $pedido = app(GestionPedidos::class)->abrir($actor, $turno->id, (string) Str::uuid(), ['modalidad' => 'rapida', 'mesaId' => '', 'mozoId' => (string) $actor->id, 'comensales' => '', 'observaciones' => '', 'destinatario' => '', 'telefono' => '', 'direccion' => '', 'referencia' => '', 'costoEnvio' => '0'])->refresh();
        $pedidos[] = $pedido->id;
        app(GestionPedidos::class)->guardar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido), ['clienteId' => '', 'comensales' => '', 'observaciones' => ''], ['uno' => ['detalleId' => '', 'productoId' => (string) $producto->id, 'cantidad' => $cantidad, 'nota' => '']]);
        app(ConfirmarPedido::class)->confirmar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido->fresh()), (string) Str::uuid());
    }
    $dir = storage_path('framework/testing/ajustes-'.$tag);
    if (competirAjustes([['anular', $pedidos[0], (string) Str::uuid()], ['iniciar', $pedidos[0], '']], $actor->id, $dir) !== [0, 2]) {
        throw new RuntimeException('Anulación y cocina no se excluyeron.');
    }
    if (competirAjustes([['anular', $pedidos[1], (string) Str::uuid()], ['cobrar', $pedidos[1], (string) Str::uuid()]], $actor->id, $dir) !== [0, 2]) {
        throw new RuntimeException('Se anuló y cobró la misma unidad.');
    }
    if (competirAjustes([['beneficio', $pedidos[2], (string) Str::uuid()], ['cobrar', $pedidos[2], (string) Str::uuid()]], $actor->id, $dir) !== [0, 2]) {
        throw new RuntimeException('El beneficio sobrescribió un cobro revisado.');
    }
    $envio = EnvioCocinaDetalle::whereHas('envio', fn ($q) => $q->where('pedido_id', $pedidos[3]))->firstOrFail();
    app(GestionCocina::class)->avanzar($actor, $envio->id, 'iniciar', '1', GestionCocina::huella($envio));
    $clave = (string) Str::uuid();
    if (competirAjustes([['anular', $pedidos[3], $clave], ['anular', $pedidos[3], $clave]], $actor->id, $dir) !== [0, 0] || AnulacionPedido::where('pedido_id', $pedidos[3])->count() !== 1 || MovimientoInventario::where('usuario_id', $actor->id)->where('tipo', 'merma')->count() !== 1 || $articulo->fresh()->stock_actual !== '19.0000') {
        throw new RuntimeException('La anulación repetida duplicó la merma.');
    }
    $clave = (string) Str::uuid();
    if (competirAjustes([['beneficio', $pedidos[4], $clave], ['beneficio', $pedidos[4], $clave]], $actor->id, $dir) !== [0, 0] || BeneficioPedido::where('pedido_id', $pedidos[4])->count() !== 1) {
        throw new RuntimeException('El beneficio repetido duplicó la autorización.');
    }
    foreach (Pedido::whereIn('id', $pedidos)->with('detalles')->get() as $p) {
        foreach ($p->detalles as $d) {
            if (bccomp(bcadd($d->cantidad_anulada, $d->cantidad_cobrada, 4), $d->cantidad, 4) > 0) {
                throw new RuntimeException('Se cobró cantidad anulada.');
            }
        }
    }
    echo json_encode(['result' => 'passed', 'checks' => ['cancellation versus kitchen', 'cancellation versus collection', 'benefit versus collection', 'duplicate cancellation and waste', 'duplicate benefit']], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $detalles = DB::table('pedido_detalles')->whereIn('pedido_id', $pedidos)->pluck('id');
    $anulaciones = DB::table('anulaciones_pedido')->whereIn('pedido_id', $pedidos)->pluck('id');
    $envios = DB::table('envios_cocina')->whereIn('pedido_id', $pedidos)->pluck('id');
    $cobros = DB::table('cobros')->whereIn('pedido_id', $pedidos)->pluck('id');
    DB::table('anulacion_pedido_detalles')->whereIn('anulacion_pedido_id', $anulaciones)->delete();
    DB::table('anulaciones_pedido')->whereIn('id', $anulaciones)->delete();
    DB::table('beneficios_pedido')->whereIn('pedido_id', $pedidos)->delete();
    DB::table('pagos')->whereIn('cobro_id', $cobros)->delete();
    DB::table('cobro_detalles')->whereIn('cobro_id', $cobros)->delete();
    DB::table('cobros')->whereIn('id', $cobros)->delete();
    DB::table('envio_cocina_detalles')->whereIn('envio_cocina_id', $envios)->delete();
    DB::table('envios_cocina')->whereIn('id', $envios)->delete();
    DB::table('reservas_inventario')->whereIn('pedido_detalle_id', $detalles)->delete();
    DB::table('pedido_detalles')->whereIn('id', $detalles)->delete();
    DB::table('confirmaciones_pedido')->whereIn('pedido_id', $pedidos)->delete();
    DB::table('pedidos')->whereIn('id', $pedidos)->delete();
    if ($actor) {
        DB::table('movimientos_inventario')->where('usuario_id', $actor->id)->delete();
    }if ($turno) {
        DB::table('movimientos_caja')->where('turno_caja_id', $turno->id)->delete();
    }
    foreach (['turnos_caja' => $turno, 'cajas' => $caja, 'productos' => $producto, 'articulos' => $articulo, 'areas_preparacion' => $area, 'categorias_producto' => $categoria, 'unidades_medida' => $unidad, 'motivos_beneficio' => $motivo, 'medios_pago' => $medio] as $tabla => $modelo) {
        if ($modelo) {
            DB::table($tabla)->where('id', $modelo->id)->delete();
        }
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
