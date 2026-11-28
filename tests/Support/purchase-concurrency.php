<?php

use App\Models\Articulo;
use App\Models\Compra;
use App\Models\MedioPago;
use App\Models\MovimientoInventario;
use App\Models\PagoCompra;
use App\Models\Proveedor;
use App\Models\TipoComprobante;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Compras\GestionCompras;
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
    $compra = Compra::findOrFail((int) $id);
    $actor = User::findOrFail((int) $actorId);
    file_put_contents($directorio.'/ready-'.$indice, '1');
    $limite = microtime(true) + 15;
    while (! is_file($directorio.'/go')) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('No llegó la señal de inicio de compras.');
        }
        usleep(10000);
    }
    try {
        $servicio = app(GestionCompras::class);
        $datos = ['origen' => 'empresa', 'medioId' => (string) MedioPago::where('codigo', 'race-'.substr($actor->username, 4))->value('id'), 'turnoId' => '', 'referencia' => '', 'observaciones' => '', 'monto' => $modo === 'pagar' ? '8' : '2'];
        if ($modo === 'recibir') {
            $servicio->confirmar($actor, $compra->id, GestionCompras::huella($compra), $clave, $datos);
        } else {
            $servicio->registrarPago($actor, $compra->id, $clave, $datos);
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
function competirCompras(array $operaciones, int $actorId, string $directorio): array
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
                throw new RuntimeException('La prueba de compras excedió el tiempo permitido.');
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
$actor = $proveedor = $unidad = $articulo = $documento = $medio = null;
$compras = [];
try {
    $actor = User::create(['name' => 'Prueba compras', 'username' => 'buy_'.$tag, 'password' => Str::random(40), 'activo' => true]);
    foreach (['compras.crear', 'compras.confirmar', 'pagos_proveedor.registrar'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
        $actor->givePermissionTo($permiso);
    }
    $proveedor = Proveedor::create(['nombre' => 'Proveedor concurrente '.$tag]);
    $unidad = UnidadMedida::create(['codigo' => 'b-'.$tag, 'nombre' => 'Unidad prueba', 'magnitud' => 'unidad', 'factor_base' => 1]);
    $articulo = Articulo::create(['codigo' => 'BUY-'.$tag, 'nombre' => 'Insumo prueba', 'tipo' => 'insumo', 'unidad_medida_id' => $unidad->id]);
    $documento = TipoComprobante::firstOrCreate(['codigo' => '00'], ['nombre' => 'Otro documento', 'electronico' => false]);
    // Código reservado exclusivamente por este script, que se limpia en finally.
    $medio = MedioPago::create(['codigo' => 'race-'.$tag, 'nombre' => 'Digital prueba', 'afecta_efectivo' => false]);
    $servicio = app(GestionCompras::class);
    foreach (['1', '3'] as $costo) {
        $compras[] = $servicio->guardar($actor, null, (string) Str::uuid(), ['proveedorId' => (string) $proveedor->id, 'tipoId' => (string) $documento->id,
            'serie' => '', 'numero' => '', 'fecha' => now()->format('Y-m-d'), 'vencimiento' => now()->format('Y-m-d'), 'condicion' => 'credito', 'observaciones' => ''],
            ['a' => ['articuloId' => (string) $articulo->id, 'presentacionId' => '', 'cantidad' => '10', 'costo' => $costo, 'descuento' => '0', 'impuestos' => '0']])->id;
    }
    $directorio = storage_path('framework/testing/buy-'.$tag);
    $operaciones = array_map(fn (int $id) => ['recibir', $id, (string) Str::uuid()], $compras);
    if (competirCompras($operaciones, $actor->id, $directorio) !== [0, 0] || $articulo->fresh()->stock_actual !== '20.0000' || $articulo->fresh()->costo_promedio !== '2.000000') {
        throw new RuntimeException('Recepciones simultáneas perdieron cantidades o valor de inventario.');
    }
    if (competirCompras([['recibir', $compras[0], (string) Str::uuid()], ['recibir', $compras[0], (string) Str::uuid()]], $actor->id, $directorio) !== [0, 0]
        || MovimientoInventario::where('origen_type', Compra::class)->whereIn('origen_id', $compras)->count() !== 2) {
        throw new RuntimeException('Una recepción repetida duplicó movimientos.');
    }
    if (competirCompras([['pagar', $compras[0], (string) Str::uuid()], ['pagar', $compras[0], (string) Str::uuid()]], $actor->id, $directorio) !== [0, 2]
        || Compra::findOrFail($compras[0])->saldoPendiente() !== '2.00') {
        throw new RuntimeException('Pagos simultáneos superaron el saldo pendiente.');
    }
    $clave = (string) Str::uuid();
    if (competirCompras([['saldo', $compras[0], $clave], ['saldo', $compras[0], $clave]], $actor->id, $directorio) !== [0, 0]
        || Compra::findOrFail($compras[0])->saldoPendiente() !== '0.00' || PagoCompra::whereIn('compra_id', $compras)->count() !== 2) {
        throw new RuntimeException('El pago duplicado no fue idempotente.');
    }
    echo json_encode(['result' => 'passed', 'checks' => ['concurrent receipt valuation', 'duplicate receipt', 'competing payments', 'duplicate payment']], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::table('pagos_compra')->whereIn('compra_id', $compras)->delete();
    DB::table('movimientos_inventario')->where('origen_type', Compra::class)->whereIn('origen_id', $compras)->delete();
    DB::table('compra_detalles')->whereIn('compra_id', $compras)->delete();
    DB::table('compras')->whereIn('id', $compras)->delete();
    if ($articulo) {
        DB::table('articulos')->where('id', $articulo->id)->delete();
    }
    if ($unidad) {
        DB::table('unidades_medida')->where('id', $unidad->id)->delete();
    }
    if ($proveedor) {
        DB::table('proveedores')->where('id', $proveedor->id)->delete();
    }
    if ($medio) {
        DB::table('medios_pago')->where('id', $medio->id)->delete();
    }
    if ($documento?->wasRecentlyCreated) {
        DB::table('tipos_comprobante')->where('id', $documento->id)->delete();
    }
    if ($actor) {
        DB::table('activity_log')->where('causer_type', User::class)->where('causer_id', $actor->id)->delete();
        // Eventos automáticos creados por las filas exclusivas del escenario.
        foreach ([[User::class, [$actor->id]], [Compra::class, $compras], [Articulo::class, $articulo ? [$articulo->id] : []], [Proveedor::class, $proveedor ? [$proveedor->id] : []]] as [$tipo, $ids]) {
            DB::table('activity_log')->where('subject_type', $tipo)->whereIn('subject_id', $ids)->delete();
        }
        DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $actor->id)->delete();
        DB::table('users')->where('id', $actor->id)->delete();
    }
}
