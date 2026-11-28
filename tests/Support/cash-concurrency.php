<?php

use App\Models\ArqueoCaja;
use App\Models\Articulo;
use App\Models\Caja;
use App\Models\Compra;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\Proveedor;
use App\Models\TipoComprobante;
use App\Models\TurnoCaja;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Cajas\GestionCajas;
use App\Services\Cajas\ResumenTurno;
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
    $actor = User::findOrFail((int) $actorId);
    $turno = $modo === 'abrir' ? null : TurnoCaja::findOrFail((int) $id);
    $resumen = $turno ? app(ResumenTurno::class)->calcular($turno) : null;
    file_put_contents($directorio.'/ready-'.$indice, '1');
    $limite = microtime(true) + 15;
    while (! is_file($directorio.'/go')) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('No llegó la señal de inicio de caja.');
        }
        usleep(10000);
    }
    try {
        $servicio = app(GestionCajas::class);
        if ($modo === 'abrir') {
            $servicio->abrir($actor, (int) $id, $actor->id, '100', '', $clave);
        } elseif ($modo === 'pagar') {
            $compra = Compra::where('usuario_id', $actor->id)->firstOrFail();
            app(GestionCompras::class)->registrarPago($actor, $compra->id, $clave, ['origen' => 'caja',
                'medioId' => (string) MedioPago::where('codigo', 'ce-'.substr($actor->username, 5))->value('id'),
                'turnoId' => (string) $id, 'monto' => '20', 'referencia' => '', 'observaciones' => '']);
        } elseif ($modo === 'cierre') {
            $servicio->arquear($actor, (int) $id, 'cierre', $resumen['esperado'], '', $clave, $resumen['huella']);
        } else {
            $servicio->movimiento($actor, (int) $id, $clave, $resumen['huella'], ['tipo' => $modo, 'monto' => $modo === 'ingreso' ? '5' : '10', 'concepto' => 'Prueba simultánea', 'motivo' => 'Movimiento de prueba concurrente']);
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
function competirCajas(array $operaciones, int $actorId, string $directorio): array
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
                throw new RuntimeException('La prueba de caja excedió el tiempo permitido.');
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
$actor = $caja = $proveedor = $unidad = $articulo = $documento = $medio = $compra = null;
$turnos = [];
try {
    $actor = User::create(['name' => 'Prueba caja', 'username' => 'cash_'.$tag, 'password' => Str::random(40), 'activo' => true]);
    foreach (['cajas.ver_todas', 'cajas.abrir', 'cajas.cerrar', 'cajas.registrar_ingreso', 'cajas.registrar_egreso', 'compras.crear', 'compras.confirmar', 'pagos_proveedor.registrar'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
        $actor->givePermissionTo($permiso);
    }
    $caja = Caja::create(['codigo' => 'race-'.$tag, 'nombre' => 'Caja prueba '.$tag]);
    $servicio = app(GestionCajas::class);
    $directorio = storage_path('framework/testing/cash-'.$tag);
    if (competirCajas([['abrir', $caja->id, (string) Str::uuid()], ['abrir', $caja->id, (string) Str::uuid()]], $actor->id, $directorio) !== [0, 2]
        || TurnoCaja::where('caja_id', $caja->id)->count() !== 1) {
        throw new RuntimeException('Dos aperturas simultáneas abrieron la misma caja.');
    }
    $turno = TurnoCaja::where('caja_id', $caja->id)->firstOrFail();
    $turnos[] = $turno->id;
    $claveCierre = (string) Str::uuid();
    if (competirCajas([['egreso', $turno->id, (string) Str::uuid()], ['cierre', $turno->id, $claveCierre]], $actor->id, $directorio) !== [0, 2]) {
        throw new RuntimeException('Egreso y cierre no resolvieron la revisión concurrente.');
    }
    $turno->refresh();
    if ($turno->estado->value === 'abierta') {
        if ($turno->efectivoEsperado() !== '90.00' || $turno->arqueos()->count() !== 0) {
            throw new RuntimeException('Egreso ganador inconsistente.');
        }
        $resumen = app(ResumenTurno::class)->calcular($turno);
        $claveCierre = (string) Str::uuid();
        $servicio->arquear($actor, $turno->id, 'cierre', '90', '', $claveCierre, $resumen['huella']);
    } elseif ($turno->efectivoEsperado() !== '100.00' || $turno->movimientos()->count() !== 0) {
        throw new RuntimeException('Un egreso se registró después del cierre.');
    }
    if (competirCajas([['cierre', $turno->id, $claveCierre], ['cierre', $turno->id, $claveCierre]], $actor->id, $directorio) !== [0, 0]
        || $turno->arqueos()->where('tipo', 'cierre')->count() !== 1) {
        throw new RuntimeException('Un cierre repetido duplicó el arqueo final.');
    }
    $proveedor = Proveedor::create(['nombre' => 'Proveedor de caja '.$tag]);
    $unidad = UnidadMedida::create(['codigo' => 'c-'.$tag, 'nombre' => 'Unidad prueba', 'magnitud' => 'unidad', 'factor_base' => 1]);
    $articulo = Articulo::create(['codigo' => 'CASH-'.$tag, 'nombre' => 'Insumo prueba', 'tipo' => 'insumo', 'unidad_medida_id' => $unidad->id]);
    $documento = TipoComprobante::firstOrCreate(['codigo' => '00'], ['nombre' => 'Otro documento', 'electronico' => false]);
    $medio = MedioPago::create(['codigo' => 'ce-'.$tag, 'nombre' => 'Efectivo prueba', 'afecta_efectivo' => true]);
    $compras = app(GestionCompras::class);
    $compra = $compras->guardar($actor, null, (string) Str::uuid(), ['proveedorId' => (string) $proveedor->id, 'tipoId' => (string) $documento->id,
        'serie' => '', 'numero' => '', 'fecha' => now()->format('Y-m-d'), 'vencimiento' => now()->format('Y-m-d'), 'condicion' => 'credito', 'observaciones' => ''],
        ['a' => ['articuloId' => (string) $articulo->id, 'presentacionId' => '', 'cantidad' => '10', 'costo' => '2', 'descuento' => '0', 'impuestos' => '0']]);
    $compras->confirmar($actor, $compra->id, GestionCompras::huella($compra->fresh()), (string) Str::uuid(), ['origen' => 'dueno', 'medioId' => (string) $medio->id, 'turnoId' => '', 'referencia' => '', 'observaciones' => '', 'monto' => '20']);
    $segundo = $servicio->abrir($actor, $caja->id, $actor->id, '100', '', (string) Str::uuid());
    $turnos[] = $segundo->id;
    if (competirCajas([['pagar', $segundo->id, (string) Str::uuid()], ['cierre', $segundo->id, (string) Str::uuid()]], $actor->id, $directorio) !== [0, 2]) {
        throw new RuntimeException('Pago de proveedor y cierre no preservaron su atomicidad.');
    }
    $segundo->refresh();
    if ($segundo->estado->value === 'cerrada') {
        if ($compra->saldoPendiente() !== '20.00' || $segundo->efectivoEsperado() !== '100.00' || $segundo->movimientos()->count() !== 0) {
            throw new RuntimeException('Se pagó desde un turno cerrado.');
        }
    } else {
        if ($compra->saldoPendiente() !== '0.00' || $segundo->efectivoEsperado() !== '80.00' || $segundo->movimientos()->count() !== 1 || $segundo->arqueos()->count() !== 0) {
            throw new RuntimeException('Pago de proveedor inconsistente.');
        }
        $servicio->arquear($actor, $segundo->id, 'cierre', '80', '', (string) Str::uuid(), app(ResumenTurno::class)->calcular($segundo)['huella']);
    }
    $tercero = $servicio->abrir($actor, $caja->id, $actor->id, '0', '', (string) Str::uuid());
    $turnos[] = $tercero->id;
    $claveIngreso = (string) Str::uuid();
    if (competirCajas([['ingreso', $tercero->id, $claveIngreso], ['ingreso', $tercero->id, $claveIngreso]], $actor->id, $directorio) !== [0, 0]
        || $tercero->efectivoEsperado() !== '5.00' || $tercero->movimientos()->count() !== 1) {
        throw new RuntimeException('Un movimiento repetido duplicó el efectivo.');
    }
    echo json_encode(['result' => 'passed', 'checks' => ['competing openings', 'expense versus close', 'duplicate close', 'supplier payment versus close', 'duplicate cash movement']], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    // Filas exclusivas de este escenario: no modifica cajas o turnos ajenos.
    if ($caja) {
        $turnos = TurnoCaja::where('caja_id', $caja->id)->pluck('id')->all();
    }
    if ($compra) {
        DB::table('pagos_compra')->where('compra_id', $compra->id)->delete();
        DB::table('movimientos_inventario')->where('origen_type', Compra::class)->where('origen_id', $compra->id)->delete();
        DB::table('compra_detalles')->where('compra_id', $compra->id)->delete();
        DB::table('compras')->where('id', $compra->id)->delete();
    }
    $movimientos = MovimientoCaja::whereIn('turno_caja_id', $turnos)->pluck('id')->all();
    $arqueos = ArqueoCaja::whereIn('turno_caja_id', $turnos)->pluck('id')->all();
    DB::table('movimientos_caja')->whereIn('turno_caja_id', $turnos)->delete();
    DB::table('arqueos_caja')->whereIn('turno_caja_id', $turnos)->delete();
    DB::table('turnos_caja')->whereIn('id', $turnos)->delete();
    if ($caja) {
        DB::table('cajas')->where('id', $caja->id)->delete();
    }
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
        foreach ([[User::class, [$actor->id]], [TurnoCaja::class, $turnos], [ArqueoCaja::class, $arqueos], [MovimientoCaja::class, $movimientos], [Compra::class, $compra ? [$compra->id] : []], [Articulo::class, $articulo ? [$articulo->id] : []], [Proveedor::class, $proveedor ? [$proveedor->id] : []]] as [$tipo, $ids]) {
            DB::table('activity_log')->where('subject_type', $tipo)->whereIn('subject_id', $ids)->delete();
        }
        DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $actor->id)->delete();
        DB::table('users')->where('id', $actor->id)->delete();
    }
}
