<?php

// Ejecutar solo en la base desechable sisrest_test, fuera de las transacciones de Pest.
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\Preparacion;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Inventario\GestionPreparaciones;
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
    $preparacion = Preparacion::findOrFail((int) $argv[2]);
    $actor = User::findOrFail((int) $argv[3]);
    $directorio = $argv[4];
    file_put_contents($directorio.'/ready-'.(int) $argv[5], '1');
    $limite = microtime(true) + 15;
    while (! is_file($directorio.'/go')) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('No llegó la señal de inicio.');
        }
        usleep(10000);
    }
    try {
        app(GestionPreparaciones::class)->confirmar($actor, $preparacion->id, GestionPreparaciones::huellaBorrador($preparacion));
        exit(0);
    } catch (ValidationException $error) {
        fwrite(STDERR, implode(' ', $error->validator->errors()->all()));
        exit(2);
    }
}

/** @return array<int, int> */
function competir(array $preparaciones, int $actorId, string $directorio): array
{
    mkdir($directorio, 0700, true);
    $procesos = [];
    try {
        foreach ($preparaciones as $indice => $id) {
            $proceso = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $id, (string) $actorId, $directorio, (string) $indice], [
                0 => ['pipe', 'r'], 1 => ['file', $directorio.'/out-'.$indice, 'w'], 2 => ['file', $directorio.'/err-'.$indice, 'w'],
            ], $pipes, dirname(__DIR__, 2));
            if (! is_resource($proceso)) {
                throw new RuntimeException('No se pudo iniciar el proceso de prueba.');
            }
            fclose($pipes[0]);
            $procesos[$indice] = $proceso;
        }
        $limite = microtime(true) + 15;
        while (count(glob($directorio.'/ready-*')) !== count($procesos)) {
            if (microtime(true) > $limite) {
                throw new RuntimeException('Los procesos no quedaron listos.');
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
                throw new RuntimeException('La prueba concurrente excedió el tiempo permitido.');
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
$actor = null;
$unidades = [];
$articulos = [];
$producto = null;
$receta = null;
$borradores = [];
try {
    $actor = User::create(['name' => 'Prueba concurrente', 'username' => 'race_'.$tag, 'password' => Str::random(40), 'activo' => true]);
    foreach (['preparaciones.crear', 'preparaciones.confirmar'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
        $actor->givePermissionTo($permiso);
    }
    $gramo = UnidadMedida::create(['codigo' => 'g-'.$tag, 'nombre' => 'Gramo prueba', 'magnitud' => 'masa', 'factor_base' => 1, 'activo' => true]);
    $unidad = UnidadMedida::create(['codigo' => 'u-'.$tag, 'nombre' => 'Unidad prueba', 'magnitud' => 'unidad', 'factor_base' => 1, 'activo' => true]);
    $unidades = [$gramo->id, $unidad->id];
    $entrada = Articulo::create(['codigo' => 'IN-'.$tag, 'nombre' => 'Entrada de prueba', 'tipo' => 'insumo', 'unidad_medida_id' => $gramo->id, 'stock_actual' => '2', 'costo_promedio' => '1.25', 'activo' => true]);
    $salida = Articulo::create(['codigo' => 'OUT-'.$tag, 'nombre' => 'Salida de prueba', 'tipo' => 'preparado', 'unidad_medida_id' => $unidad->id, 'activo' => true]);
    $articulos = [$entrada->id, $salida->id];
    $producto = Producto::create(['codigo' => 'P-'.$tag, 'nombre' => 'Producto de prueba', 'tipo' => 'plato', 'articulo_id' => $salida->id, 'activo' => true]);
    $receta = Receta::create(['producto_id' => $producto->id, 'rendimiento' => '1', 'activo' => true]);
    $receta->detalles()->create(['articulo_id' => $entrada->id, 'cantidad' => '2']);
    $servicio = app(GestionPreparaciones::class);
    for ($i = 0; $i < 2; $i++) {
        $calculo = $servicio->previsualizar($actor, $producto->id, '1');
        $borradores[] = $servicio->guardar($actor, null, (string) Str::uuid(), ['productoId' => (string) $producto->id, 'cantidad' => '1',
            'fecha' => now()->format('Y-m-d'), 'observaciones' => '', 'motivoAjuste' => ''], [], $calculo['calculoHash'])->id;
    }
    $directorio = storage_path('framework/testing/race-'.$tag);
    $codigos = competir($borradores, $actor->id, $directorio);
    $movimientos = fn () => MovimientoInventario::where('origen_type', Preparacion::class)->whereIn('origen_id', $borradores)->count();
    if ($codigos !== [0, 2] || $entrada->fresh()->stock_actual !== '0.0000' || $salida->fresh()->stock_actual !== '1.0000' || $movimientos() !== 2) {
        throw new RuntimeException('Dos preparaciones concurrentes consumieron existencias incorrectamente.');
    }
    $pendiente = Preparacion::whereIn('id', $borradores)->where('estado', 'borrador')->firstOrFail();
    $entrada->refresh()->update(['stock_actual' => '2']); // Nueva existencia exclusiva de este escenario de prueba.
    $codigos = competir([$pendiente->id, $pendiente->id], $actor->id, $directorio);
    if ($codigos !== [0, 0] || $entrada->fresh()->stock_actual !== '0.0000' || $salida->fresh()->stock_actual !== '2.0000' || $movimientos() !== 4) {
        throw new RuntimeException('Falló la confirmación repetida: '.json_encode(['codigos' => $codigos, 'entrada' => $entrada->fresh()->stock_actual, 'salida' => $salida->fresh()->stock_actual, 'movimientos' => $movimientos()], JSON_THROW_ON_ERROR));
    }
    echo json_encode(['result' => 'passed', 'checks' => ['competing preparations', 'duplicate concurrent confirmation']], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::table('movimientos_inventario')->where('origen_type', Preparacion::class)->whereIn('origen_id', $borradores)->delete();
    DB::table('preparacion_detalles')->whereIn('preparacion_id', $borradores)->delete();
    DB::table('preparaciones')->whereIn('id', $borradores)->delete();
    if ($receta) {
        DB::table('receta_detalles')->where('receta_id', $receta->id)->delete();
        DB::table('recetas')->where('id', $receta->id)->delete();
    }
    if ($producto) {
        DB::table('productos')->where('id', $producto->id)->delete();
    }
    DB::table('articulos')->whereIn('id', $articulos)->delete();
    DB::table('unidades_medida')->whereIn('id', $unidades)->delete();
    if ($actor) {
        DB::table('activity_log')->where(function ($query) use ($actor, $borradores, $producto, $receta, $articulos) {
            $query->where(fn ($q) => $q->where('causer_type', User::class)->where('causer_id', $actor->id))
                ->orWhere(fn ($q) => $q->where('subject_type', Preparacion::class)->whereIn('subject_id', $borradores))
                ->orWhere(fn ($q) => $q->where('subject_type', Articulo::class)->whereIn('subject_id', $articulos))
                ->orWhere(fn ($q) => $q->where('subject_type', User::class)->where('subject_id', $actor->id));
            if ($producto) {
                $query->orWhere(fn ($q) => $q->where('subject_type', Producto::class)->where('subject_id', $producto->id));
            }
            if ($receta) {
                $query->orWhere(fn ($q) => $q->where('subject_type', Receta::class)->where('subject_id', $receta->id));
            }
        })->delete();
        DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $actor->id)->delete();
        DB::table('users')->where('id', $actor->id)->delete();
    }
}
