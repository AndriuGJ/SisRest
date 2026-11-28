<?php

use App\Models\AreaPreparacion;
use App\Models\Articulo;
use App\Models\Caja;
use App\Models\CategoriaProducto;
use App\Models\ConfirmacionPedido;
use App\Models\EnvioCocinaDetalle;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\TurnoCaja;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Cocina\GestionCocina;
use App\Services\Pos\ConfirmarPedido;
use App\Services\Pos\GestionEntregas;
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
    $detalle = $modo === 'iniciar' ? EnvioCocinaDetalle::findOrFail((int) $id) : null;
    $pedido = $modo === 'entregar' ? PedidoDetalle::findOrFail((int) $id)->pedido : ($modo === 'confirmar' ? Pedido::findOrFail((int) $id) : null);
    $version = $detalle ? GestionCocina::huella($detalle) : GestionPedidos::huella($pedido);
    file_put_contents($directorio.'/ready-'.$indice, '1');
    $limite = microtime(true) + 15;
    while (! is_file($directorio.'/go')) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('No llegó la señal de cocina.');
        }
        usleep(10000);
    }
    try {
        if ($modo === 'confirmar') {
            app(ConfirmarPedido::class)->confirmar($actor, (int) $id, $turno->id, $version, $clave);
        } elseif ($modo === 'iniciar') {
            app(GestionCocina::class)->avanzar($actor, (int) $id, 'iniciar', '1', $version);
        } else {
            app(GestionEntregas::class)->entregar($actor, $pedido->id, (int) $id, '1', $version);
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
function competirCocina(array $operaciones, int $actorId, string $directorio): array
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
                throw new RuntimeException('La prueba de cocina excedió el tiempo permitido.');
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
$actor = $caja = $turno = $unidad = $categoria = $area = $articulo = $producto = null;
$pedidos = [];
try {
    $actor = User::create(['name' => 'Prueba cocina', 'username' => 'cocina_'.$tag, 'password' => Str::random(40), 'activo' => true]);
    foreach (['pos.ver', 'pedidos.ver_todos', 'pedidos.crear', 'pedidos.editar', 'pedidos.enviar', 'entregas.confirmar', 'cocina.ver', 'cocina.preparar', 'cocina.marcar_listo'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
        $actor->givePermissionTo($permiso);
    }
    $unidad = UnidadMedida::create(['codigo' => 'u-'.$tag, 'nombre' => 'Unidad prueba', 'magnitud' => 'unidad', 'factor_base' => 1]);
    $categoria = CategoriaProducto::create(['nombre' => 'Prueba '.$tag, 'activo' => true]);
    $area = AreaPreparacion::create(['codigo' => 'c-'.$tag, 'nombre' => 'Cocina prueba']);
    $articulo = Articulo::create(['codigo' => 'C-'.$tag, 'nombre' => 'Existencia cocina prueba', 'tipo' => 'preparado', 'unidad_medida_id' => $unidad->id, 'stock_actual' => '3']);
    $producto = Producto::create(['codigo' => 'C-'.$tag, 'nombre' => 'Producto cocina prueba', 'tipo' => 'plato', 'articulo_id' => $articulo->id, 'categoria_producto_id' => $categoria->id, 'area_preparacion_id' => $area->id, 'precio_venta' => '10', 'activo' => true, 'visible_pos' => true]);
    $caja = Caja::create(['codigo' => 'c-'.$tag, 'nombre' => 'Caja prueba']);
    $turno = TurnoCaja::create(['caja_id' => $caja->id, 'usuario_id' => $actor->id, 'abierta_at' => now()]);
    foreach (['2', '2', '1'] as $cantidad) {
        $pedido = app(GestionPedidos::class)->abrir($actor, $turno->id, (string) Str::uuid(), ['modalidad' => 'rapida', 'mesaId' => '', 'mozoId' => (string) $actor->id, 'comensales' => '', 'observaciones' => '', 'destinatario' => '', 'telefono' => '', 'direccion' => '', 'referencia' => '', 'costoEnvio' => '0']);
        $pedidos[] = $pedido->id;
        app(GestionPedidos::class)->guardar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido->fresh()), ['clienteId' => '', 'comensales' => '', 'observaciones' => ''], ['uno' => ['detalleId' => '', 'productoId' => (string) $producto->id, 'cantidad' => $cantidad, 'nota' => '']]);
    }
    $dir = storage_path('framework/testing/cocina-'.$tag);
    if (competirCocina([['confirmar', $pedidos[0], (string) Str::uuid()], ['confirmar', $pedidos[1], (string) Str::uuid()]], $actor->id, $dir) !== [0, 2]
        || $articulo->fresh()->stock_reservado !== '2.0000' || ConfirmacionPedido::whereIn('pedido_id', $pedidos)->count() !== 1) {
        throw new RuntimeException('Dos confirmaciones reservaron existencias insuficientes.');
    }
    $clave = (string) Str::uuid();
    if (competirCocina([['confirmar', $pedidos[2], $clave], ['confirmar', $pedidos[2], $clave]], $actor->id, $dir) !== [0, 0]
        || $articulo->fresh()->stock_reservado !== '3.0000' || ConfirmacionPedido::whereIn('pedido_id', $pedidos)->count() !== 2) {
        throw new RuntimeException('La confirmación repetida duplicó reservas.');
    }
    $linea = EnvioCocinaDetalle::whereHas('envio', fn ($q) => $q->whereIn('pedido_id', array_slice($pedidos, 0, 2)))->firstOrFail();
    if (competirCocina([['iniciar', $linea->id, ''], ['iniciar', $linea->id, '']], $actor->id, $dir) !== [0, 2] || $linea->fresh()->cantidad_iniciada !== '1.0000') {
        throw new RuntimeException('Dos equipos sobrescribieron el avance de cocina.');
    }
    app(GestionCocina::class)->avanzar($actor, $linea->id, 'listo', '1', GestionCocina::huella($linea->fresh()));
    if (competirCocina([['entregar', $linea->pedido_detalle_id, ''], ['entregar', $linea->pedido_detalle_id, '']], $actor->id, $dir) !== [0, 2]
        || $articulo->fresh()->stock_actual !== '2.0000' || $articulo->fresh()->stock_reservado !== '2.0000'
        || MovimientoInventario::where('usuario_id', $actor->id)->count() !== 1 || $linea->fresh()->cantidad_entregada !== '1.0000') {
        throw new RuntimeException('La entrega repetida descontó stock más de una vez.');
    }
    echo json_encode(['result' => 'passed', 'checks' => ['competing stock reservations', 'duplicate batch confirmation', 'stale kitchen progress', 'duplicate physical delivery']], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $detalles = DB::table('pedido_detalles')->whereIn('pedido_id', $pedidos)->pluck('id');
    $envios = DB::table('envios_cocina')->whereIn('pedido_id', $pedidos)->pluck('id');
    DB::table('envio_cocina_detalles')->whereIn('envio_cocina_id', $envios)->delete();
    DB::table('envios_cocina')->whereIn('id', $envios)->delete();
    DB::table('reservas_inventario')->whereIn('pedido_detalle_id', $detalles)->delete();
    DB::table('pedido_detalles')->whereIn('id', $detalles)->delete();
    DB::table('confirmaciones_pedido')->whereIn('pedido_id', $pedidos)->delete();
    DB::table('pedidos')->whereIn('id', $pedidos)->delete();
    if ($actor) {
        DB::table('movimientos_inventario')->where('usuario_id', $actor->id)->delete();
    }
    foreach (['turnos_caja' => $turno, 'cajas' => $caja, 'productos' => $producto, 'articulos' => $articulo, 'areas_preparacion' => $area, 'categorias_producto' => $categoria, 'unidades_medida' => $unidad] as $tabla => $modelo) {
        if ($modelo) {
            DB::table($tabla)->where('id', $modelo->id)->delete();
        }
    }
    if ($actor) {
        DB::table('activity_log')->where('causer_type', User::class)->where('causer_id', $actor->id)->delete();
        DB::table('activity_log')->where('subject_type', Articulo::class)->where('subject_id', $articulo?->id)->delete();
        DB::table('activity_log')->where('subject_type', Producto::class)->where('subject_id', $producto?->id)->delete();
        DB::table('activity_log')->where('subject_type', User::class)->where('subject_id', $actor->id)->delete();
        DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $actor->id)->delete();
        DB::table('users')->where('id', $actor->id)->delete();
    }
}
