<?php

use App\Models\Articulo;
use App\Models\Caja;
use App\Models\CategoriaProducto;
use App\Models\Cobro;
use App\Models\Devolucion;
use App\Models\MedioPago;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\TurnoCaja;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Cajas\GestionCajas;
use App\Services\Cajas\ResumenTurno;
use App\Services\Cobros\GestionCobros;
use App\Services\Devoluciones\GestionDevoluciones;
use App\Services\Devoluciones\ResolverProductosDevueltos;
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
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'sisrest_test') {
    throw new RuntimeException('Usa APP_ENV=testing y la base MySQL desechable sisrest_test.');
}
function argumentosReintegro(Cobro $cobro): array
{
    return [['uno' => ['detalleId' => (string) $cobro->detalles()->firstOrFail()->id, 'cantidad' => '1']], ['uno' => ['pagoId' => (string) $cobro->pagos()->firstOrFail()->id, 'monto' => '6', 'referencia' => '']]];
}
if (($argv[1] ?? '') === 'worker') {
    [$modo,$id,$actorId,$clave,$directorio,$indice] = array_slice($argv, 2);
    $actor = User::findOrFail((int) $actorId);
    $cobro = Cobro::findOrFail((int) $id);
    $turno = $cobro->turnoCaja;
    $pedido = $cobro->pedido;
    if ($modo === 'refund') {
        [$seleccion,$pagos] = argumentosReintegro($cobro);
        $plan = app(GestionDevoluciones::class)->previsualizar($actor, $cobro->id, $turno->id, $seleccion, $pagos, false, 'Reintegro concurrente de prueba');
    } elseif (in_array($modo, ['return', 'cancel'], true)) {
        $detalle = $cobro->devoluciones()->firstOrFail()->detalles()->firstOrFail();
        $accion = $modo === 'return' ? 'retorno' : 'cancelar_pendiente';
        $destinos = [$detalle->detalleCobro->pedido_detalle_id => 'reingreso'];
        $plan = app(ResolverProductosDevueltos::class)->previsualizar($actor, $detalle->id, '1', $accion, $destinos, 'Resolución concurrente de prueba');
    } elseif ($modo === 'deliver') {
        $version = GestionPedidos::huella($pedido);
    } else {
        $version = app(ResumenTurno::class)->calcular($turno)['huella'];
    }
    file_put_contents($directorio.'/ready-'.$indice, '1');
    $limite = microtime(true) + 15;
    while (! is_file($directorio.'/go')) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('Sin señal concurrente.');
        } usleep(10000);
    }
    try {
        if ($modo === 'refund') {
            app(GestionDevoluciones::class)->devolver($actor, $cobro->id, $turno->id, $seleccion, $pagos, false, 'Reintegro concurrente de prueba', $clave, $plan['huella']);
        } elseif (in_array($modo, ['return', 'cancel'], true)) {
            app(ResolverProductosDevueltos::class)->resolver($actor, $detalle->id, '1', $accion, $destinos, 'Resolución concurrente de prueba', $clave, $plan['huella']);
        } elseif ($modo === 'deliver') {
            app(GestionEntregas::class)->entregar($actor, $pedido->id, $cobro->detalles()->first()->pedido_detalle_id, '1', $version);
        } else {
            app(GestionCajas::class)->arquear($actor, $turno->id, 'cierre', $turno->efectivoEsperado(), 'Cierre concurrente de prueba', $clave, $version);
        }
        exit(0);
    } catch (ValidationException $e) {
        fwrite(STDERR, implode(' ', $e->validator->errors()->all()));
        exit(2);
    }
}

/**
 * @param  array<int,array{0:string,1:int,2:string}>  $operaciones
 * @return array<int,int>
 */
function competirDevoluciones(array $operaciones, int $actorId, string $directorio): array
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
$actor = $unidad = $categoria = $articulo = $producto = $caja = $turno = $medio = null;
$pedidos = [];
$cobros = [];
try {
    $actor = User::create(['name' => 'Prueba devoluciones', 'username' => 'dev_'.$tag, 'password' => Str::random(40), 'activo' => true]);
    foreach (['pos.ver', 'pedidos.ver_todos', 'pedidos.crear', 'pedidos.editar', 'pedidos.enviar', 'entregas.confirmar', 'cobros.crear', 'cajas.ver_propias', 'devoluciones.crear', 'cajas.registrar_egreso', 'pedidos.anular_enviados', 'inventario.ajustar', 'cajas.cerrar'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
        $actor->givePermissionTo($permiso);
    }
    $medio = MedioPago::create(['codigo' => 'dev-'.$tag, 'nombre' => 'Efectivo prueba devolución', 'afecta_efectivo' => true, 'activo' => true]);
    $unidad = UnidadMedida::create(['codigo' => 'u-'.$tag, 'nombre' => 'Unidad prueba', 'magnitud' => 'unidad', 'factor_base' => 1]);
    $categoria = CategoriaProducto::create(['nombre' => 'Devolución '.$tag, 'activo' => true]);
    $articulo = Articulo::create(['codigo' => 'DEV-'.$tag, 'nombre' => 'Existencia devolución', 'tipo' => 'reventa', 'unidad_medida_id' => $unidad->id, 'stock_actual' => '20', 'costo_promedio' => '2']);
    $producto = Producto::create(['codigo' => 'DEV-'.$tag, 'nombre' => 'Producto devolución', 'tipo' => 'reventa', 'articulo_id' => $articulo->id, 'categoria_producto_id' => $categoria->id, 'precio_venta' => '6', 'activo' => true, 'visible_pos' => true]);
    $caja = Caja::create(['codigo' => 'dev-'.$tag, 'nombre' => 'Caja devolución']);
    $turno = TurnoCaja::create(['caja_id' => $caja->id, 'usuario_id' => $actor->id, 'abierta_at' => now(), 'fondo_inicial' => '100']);
    for ($i = 0; $i < 6; $i++) {
        $pedido = app(GestionPedidos::class)->abrir($actor, $turno->id, (string) Str::uuid(), ['modalidad' => 'rapida', 'mesaId' => '', 'mozoId' => (string) $actor->id, 'comensales' => '', 'observaciones' => '', 'destinatario' => '', 'telefono' => '', 'direccion' => '', 'referencia' => '', 'costoEnvio' => '0'])->refresh();
        $pedidos[] = $pedido->id;
        app(GestionPedidos::class)->guardar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido), ['clienteId' => '', 'comensales' => '', 'observaciones' => ''], ['uno' => ['detalleId' => '', 'productoId' => (string) $producto->id, 'cantidad' => '1', 'nota' => '']]);
        app(ConfirmarPedido::class)->confirmar($actor, $pedido->id, $turno->id, GestionPedidos::huella($pedido->fresh()), (string) Str::uuid());
        $linea = $pedido->detalles()->first();
        $datos = ['clienteId' => '', 'grupoPagador' => '', 'incluirEnvio' => false];
        $seleccion = ['uno' => ['detalleId' => (string) $linea->id, 'cantidad' => '1']];
        $pagos = ['uno' => ['medioId' => (string) $medio->id, 'monto' => '6', 'entregado' => '20', 'referencia' => '']];
        $plan = app(GestionCobros::class)->previsualizar($actor, $pedido->id, $turno->id, $datos, $seleccion, $pagos);
        $cobro = app(GestionCobros::class)->cobrar($actor, $pedido->id, $turno->id, (string) Str::uuid(), $plan['huella'], $datos, $seleccion, $pagos);
        $cobros[] = $cobro->id;
        if ($i < 5) {
            app(GestionEntregas::class)->entregar($actor, $pedido->id, $linea->id, '1', GestionPedidos::huella($pedido->fresh()));
        }
    }
    $dir = storage_path('framework/testing/devoluciones-'.$tag);
    if (competirDevoluciones([['refund', $cobros[0], (string) Str::uuid()], ['refund', $cobros[0], (string) Str::uuid()]], $actor->id, $dir) !== [0, 2]) {
        throw new RuntimeException('Se devolvió dos veces el mismo saldo.');
    }
    $clave = (string) Str::uuid();
    if (competirDevoluciones([['refund', $cobros[1], $clave], ['refund', $cobros[1], $clave]], $actor->id, $dir) !== [0, 0] || Devolucion::where('cobro_id', $cobros[1])->count() !== 1) {
        throw new RuntimeException('La devolución repetida duplicó dinero.');
    }
    foreach ([2, 3, 5] as $i) {
        $cobro = Cobro::findOrFail($cobros[$i]);
        [$sel,$pag] = argumentosReintegro($cobro);
        $plan = app(GestionDevoluciones::class)->previsualizar($actor, $cobro->id, $turno->id, $sel, $pag, false, 'Reintegro concurrente de prueba');
        app(GestionDevoluciones::class)->devolver($actor, $cobro->id, $turno->id, $sel, $pag, false, 'Reintegro concurrente de prueba', (string) Str::uuid(), $plan['huella']);
    }
    $clave = (string) Str::uuid();
    if (competirDevoluciones([['return', $cobros[2], $clave], ['return', $cobros[2], $clave]], $actor->id, $dir) !== [0, 0]) {
        throw new RuntimeException('El reintento de retorno no es idempotente.');
    }
    if (competirDevoluciones([['return', $cobros[3], (string) Str::uuid()], ['return', $cobros[3], (string) Str::uuid()]], $actor->id, $dir) !== [0, 2] || MovimientoInventario::where('usuario_id', $actor->id)->where('tipo', 'reingreso')->count() !== 2) {
        throw new RuntimeException('Se reingresó el mismo producto dos veces.');
    }
    if (competirDevoluciones([['cancel', $cobros[5], (string) Str::uuid()], ['deliver', $cobros[5], '']], $actor->id, $dir) !== [0, 2]) {
        throw new RuntimeException('Se canceló y entregó la misma unidad.');
    }
    if (competirDevoluciones([['refund', $cobros[4], (string) Str::uuid()], ['close', $cobros[4], (string) Str::uuid()]], $actor->id, $dir) !== [0, 2]) {
        throw new RuntimeException('La devolución pasó con un cierre desactualizado.');
    }
    if (! in_array($turno->efectivoEsperado(), ['100.00', '106.00'], true)) {
        throw new RuntimeException('El efectivo no concilia con los reintegros.');
    }
    echo json_encode(['result' => 'passed', 'checks' => ['same refund balance', 'duplicate refund UUID', 'duplicate return UUID', 'same product return', 'paid cancellation versus delivery', 'refund versus cash close']], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $devs = DB::table('devoluciones')->whereIn('cobro_id', $cobros)->pluck('id');
    $detDev = DB::table('devolucion_detalles')->whereIn('devolucion_id', $devs)->pluck('id');
    $res = DB::table('resoluciones_devolucion')->whereIn('devolucion_detalle_id', $detDev)->pluck('id');
    DB::table('resolucion_devolucion_detalles')->whereIn('resolucion_devolucion_id', $res)->delete();
    DB::table('resoluciones_devolucion')->whereIn('id', $res)->delete();
    DB::table('devolucion_pagos')->whereIn('devolucion_id', $devs)->delete();
    DB::table('devolucion_detalles')->whereIn('id', $detDev)->delete();
    DB::table('devoluciones')->whereIn('id', $devs)->delete();
    $detalles = DB::table('pedido_detalles')->whereIn('pedido_id', $pedidos)->pluck('id');
    $envios = DB::table('envios_cocina')->whereIn('pedido_id', $pedidos)->pluck('id');
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
        DB::table('arqueos_caja')->where('turno_caja_id', $turno->id)->delete();
        DB::table('movimientos_caja')->where('turno_caja_id', $turno->id)->delete();
    }
    foreach (['turnos_caja' => $turno, 'cajas' => $caja, 'productos' => $producto, 'articulos' => $articulo, 'categorias_producto' => $categoria, 'unidades_medida' => $unidad, 'medios_pago' => $medio] as $tabla => $modelo) {
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
