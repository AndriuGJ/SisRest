<?php

use App\Models\Articulo;
use App\Models\Caja;
use App\Models\CategoriaProducto;
use App\Models\Cobro;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\TurnoCaja;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Cajas\GestionCajas;
use App\Services\Cajas\ResumenTurno;
use App\Services\Cobros\GestionCobros;
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
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'sisrest_test') {
    throw new RuntimeException('Usa APP_ENV=testing y la base MySQL desechable sisrest_test.');
}

if (($argv[1] ?? '') === 'worker') {
    [$modo, $id, $actorId, $clave, $directorio, $indice] = array_slice($argv, 2);
    $actor = User::findOrFail((int) $actorId);
    $turno = TurnoCaja::where('usuario_id', $actor->id)->firstOrFail();
    $datos = ['clienteId' => '', 'grupoPagador' => 'Grupo de prueba', 'incluirEnvio' => $modo === 'envio'];
    $seleccion = $pagos = [];
    if ($modo === 'cerrar') {
        $resumen = app(ResumenTurno::class)->calcular($turno);
    } else {
        $pedido = Pedido::findOrFail((int) $id);
        if ($modo !== 'envio') {
            $detalle = $pedido->detalles()->whereNull('detalle_padre_id')->firstOrFail();
            $seleccion['uno'] = ['detalleId' => (string) $detalle->id, 'cantidad' => '1'];
        }
        $codigoMedio = $modo === 'envio' ? 'digital-race' : 'cash-race';
        $pagos['uno'] = ['medioId' => (string) MedioPago::where('codigo', $codigoMedio)->firstOrFail()->id, 'monto' => $modo === 'envio' ? '5' : '6', 'entregado' => '', 'referencia' => ''];
        $plan = app(GestionCobros::class)->previsualizar($actor, $pedido->id, $turno->id, $datos, $seleccion, $pagos);
    }
    file_put_contents($directorio.'/ready-'.$indice, '1');
    $limite = microtime(true) + 15;
    while (! is_file($directorio.'/go')) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('No llegó la señal de cobros.');
        }
        usleep(10000);
    }
    try {
        if ($modo === 'cerrar') {
            app(GestionCajas::class)->arquear($actor, $turno->id, 'cierre', $resumen['esperado'], '', $clave, $resumen['huella']);
        } else {
            app(GestionCobros::class)->cobrar($actor, (int) $id, $turno->id, $clave, $plan['huella'], $datos, $seleccion, $pagos);
        }
        exit(0);
    } catch (ValidationException $error) {
        fwrite(STDERR, implode(' ', $error->validator->errors()->all()));
        exit(2);
    }
}

/**
 * @param  array<int,array{0:string,1:int,2:string}>  $operaciones
 * @return array<int,int>
 */
function competirCobros(array $operaciones, int $actorId, string $directorio): array
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
$actor = $caja = $turno = $unidad = $categoria = $articulo = $producto = null;
$mediosCreados = [];
$pedidos = [];
try {
    $actor = User::create(['name' => 'Prueba cobros', 'username' => 'cobros_'.$tag, 'password' => Str::random(40), 'activo' => true]);
    foreach (['pos.ver', 'pedidos.ver_todos', 'pedidos.crear', 'pedidos.editar', 'pedidos.enviar', 'cobros.crear', 'cajas.ver_propias', 'cajas.cerrar'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
        $actor->givePermissionTo($permiso);
    }
    foreach (['cash-race' => true, 'digital-race' => false] as $codigo => $efectivo) {
        $medio = MedioPago::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo, 'afecta_efectivo' => $efectivo, 'activo' => true]);
        if ($medio->wasRecentlyCreated) {
            $mediosCreados[] = $medio->id;
        }
    }
    $unidad = UnidadMedida::create(['codigo' => 'u-'.$tag, 'nombre' => 'Unidad prueba', 'magnitud' => 'unidad', 'factor_base' => 1]);
    $categoria = CategoriaProducto::create(['nombre' => 'Cobros '.$tag, 'activo' => true]);
    $articulo = Articulo::create(['codigo' => 'CO-'.$tag, 'nombre' => 'Existencia cobros prueba', 'tipo' => 'reventa', 'unidad_medida_id' => $unidad->id, 'stock_actual' => '20']);
    $producto = Producto::create(['codigo' => 'CO-'.$tag, 'nombre' => 'Producto cobros prueba', 'tipo' => 'reventa', 'articulo_id' => $articulo->id, 'categoria_producto_id' => $categoria->id, 'precio_venta' => '6', 'activo' => true, 'visible_pos' => true]);
    $caja = Caja::create(['codigo' => 'co-'.$tag, 'nombre' => 'Caja cobros prueba']);
    $turno = TurnoCaja::create(['caja_id' => $caja->id, 'usuario_id' => $actor->id, 'abierta_at' => now()]);
    for ($i = 0; $i < 4; $i++) {
        $pedido = app(GestionPedidos::class)->abrir($actor, $turno->id, (string) Str::uuid(), ['modalidad' => $i === 2 ? 'delivery' : 'rapida', 'mesaId' => '', 'mozoId' => (string) $actor->id, 'comensales' => '', 'observaciones' => '', 'destinatario' => 'Cliente prueba', 'telefono' => '999888777', 'direccion' => 'Dirección prueba', 'referencia' => '', 'costoEnvio' => $i === 2 ? '5' : '0'])->refresh();
        $pedidos[] = $pedido->id;
        app(GestionPedidos::class)->guardar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido), ['clienteId' => '', 'comensales' => '', 'observaciones' => ''], ['uno' => ['detalleId' => '', 'productoId' => (string) $producto->id, 'cantidad' => '1', 'nota' => '']]);
        app(ConfirmarPedido::class)->confirmar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido->fresh()), (string) Str::uuid());
    }
    $dir = storage_path('framework/testing/cobros-'.$tag);
    if (competirCobros([['cobrar', $pedidos[0], (string) Str::uuid()], ['cobrar', $pedidos[0], (string) Str::uuid()]], $actor->id, $dir) !== [0, 2]
        || Cobro::where('pedido_id', $pedidos[0])->count() !== 1 || MovimientoCaja::where('turno_caja_id', $turno->id)->count() !== 1) {
        throw new RuntimeException('Se cobraron dos veces las mismas cantidades.');
    }
    $clave = (string) Str::uuid();
    if (competirCobros([['cobrar', $pedidos[1], $clave], ['cobrar', $pedidos[1], $clave]], $actor->id, $dir) !== [0, 0]
        || Cobro::where('pedido_id', $pedidos[1])->count() !== 1 || $turno->efectivoEsperado() !== '12.00') {
        throw new RuntimeException('El UUID repetido duplicó el ingreso o el cobro.');
    }
    if (competirCobros([['envio', $pedidos[2], (string) Str::uuid()], ['envio', $pedidos[2], (string) Str::uuid()]], $actor->id, $dir) !== [0, 2]
        || Cobro::where('pedido_id', $pedidos[2])->sum('cargo_envio') != 5 || $turno->efectivoEsperado() !== '12.00') {
        throw new RuntimeException('Se duplicó el cargo de envío o se ingresó efectivo digital.');
    }
    if (competirCobros([['cobrar', $pedidos[3], (string) Str::uuid()], ['cerrar', $turno->id, (string) Str::uuid()]], $actor->id, $dir) !== [0, 2]) {
        throw new RuntimeException('Cobro y cierre no se excluyeron correctamente.');
    }
    $cerrado = $turno->fresh()->estado->value === 'cerrada';
    if (Cobro::where('pedido_id', $pedidos[3])->count() !== ($cerrado ? 0 : 1) || $turno->efectivoEsperado() !== ($cerrado ? '12.00' : '18.00')
        || $articulo->fresh()->stock_actual !== '20.0000' || $articulo->fresh()->stock_reservado !== '4.0000') {
        throw new RuntimeException('El cobro versus cierre dejó datos parciales o modificó inventario.');
    }
    echo json_encode(['result' => 'passed', 'checks' => ['competing item collections', 'duplicate collection UUID', 'delivery fee collected once', 'collection versus cash close']], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $detalles = DB::table('pedido_detalles')->whereIn('pedido_id', $pedidos)->pluck('id');
    $cobros = DB::table('cobros')->whereIn('pedido_id', $pedidos)->pluck('id');
    DB::table('pagos')->whereIn('cobro_id', $cobros)->delete();
    DB::table('cobro_detalles')->whereIn('cobro_id', $cobros)->delete();
    DB::table('cobros')->whereIn('id', $cobros)->delete();
    DB::table('reservas_inventario')->whereIn('pedido_detalle_id', $detalles)->delete();
    DB::table('pedido_detalles')->whereIn('id', $detalles)->delete();
    DB::table('confirmaciones_pedido')->whereIn('pedido_id', $pedidos)->delete();
    DB::table('entregas_delivery')->whereIn('pedido_id', $pedidos)->delete();
    DB::table('pedidos')->whereIn('id', $pedidos)->delete();
    if ($turno) {
        DB::table('movimientos_caja')->where('turno_caja_id', $turno->id)->delete();
        DB::table('arqueos_caja')->where('turno_caja_id', $turno->id)->delete();
    }
    foreach (['turnos_caja' => $turno, 'cajas' => $caja, 'productos' => $producto, 'articulos' => $articulo, 'categorias_producto' => $categoria, 'unidades_medida' => $unidad] as $tabla => $modelo) {
        if ($modelo) {
            DB::table($tabla)->where('id', $modelo->id)->delete();
        }
    }
    DB::table('medios_pago')->whereIn('id', $mediosCreados)->delete();
    if ($actor) {
        DB::table('activity_log')->where('causer_type', User::class)->where('causer_id', $actor->id)->delete();
        DB::table('activity_log')->where('subject_type', Articulo::class)->where('subject_id', $articulo?->id)->delete();
        DB::table('activity_log')->where('subject_type', Producto::class)->where('subject_id', $producto?->id)->delete();
        DB::table('activity_log')->where('subject_type', User::class)->where('subject_id', $actor->id)->delete();
        DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $actor->id)->delete();
        DB::table('users')->where('id', $actor->id)->delete();
    }
}
