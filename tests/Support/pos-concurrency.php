<?php

use App\Models\Caja;
use App\Models\Mesa;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\Salon;
use App\Models\TurnoCaja;
use App\Models\User;
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

require_once __DIR__.'/AutorizacionAnulacion.php';
autorizacionAnulacionPrueba('Descarte de prueba concurrente');

if (($argv[1] ?? '') === 'worker') {
    [$modo, $id, $actorId, $clave, $directorio, $indice] = array_slice($argv, 2);
    $actor = User::findOrFail((int) $actorId);
    $turno = TurnoCaja::where('usuario_id', $actor->id)->firstOrFail();
    $version = $modo === 'abrir' ? '' : GestionPedidos::huella(Pedido::findOrFail((int) $id));
    file_put_contents($directorio.'/ready-'.$indice, '1');
    $limite = microtime(true) + 15;
    while (! is_file($directorio.'/go')) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('No llegó la señal POS.');
        } usleep(10000);
    }
    try {
        $servicio = app(GestionPedidos::class);
        if ($modo === 'abrir') {
            $servicio->abrir($actor, $turno->id, $clave, ['modalidad' => 'mesa', 'mesaId' => $id, 'mozoId' => (string) $actor->id, 'comensales' => '', 'observaciones' => '', 'destinatario' => '', 'telefono' => '', 'direccion' => '', 'referencia' => '', 'costoEnvio' => '0']);
        } elseif ($modo === 'guardar') {
            $servicio->guardar($actor, (int) $id, $turno->id, $version, ['clienteId' => '', 'comensales' => '', 'observaciones' => 'Edición simultánea'], []);
        } else {
            $servicio->descartar($actor, (int) $id, $version, 'Descarte de prueba concurrente', ...autorizacionAnulacionPrueba('Descarte de prueba concurrente'));
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
function competirPos(array $operaciones, int $actorId, string $directorio): array
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
$actor = $salon = $mesa = $caja = $turno = null;
$pedidos = [];
try {
    $actor = User::create(['name' => 'Prueba POS', 'username' => 'pos_'.$tag, 'password' => Str::random(40), 'activo' => true]);
    foreach (['pos.ver', 'pedidos.ver_todos', 'pedidos.crear', 'pedidos.editar', 'mesas.liberar'] as $permiso) {
        Permission::findOrCreate($permiso, 'web');
        $actor->givePermissionTo($permiso);
    }
    $salon = Salon::create(['codigo' => 'pos-'.$tag, 'nombre' => 'Salón prueba']);
    $mesa = Mesa::create(['salon_id' => $salon->id, 'codigo' => 'Mesa prueba']);
    $caja = Caja::create(['codigo' => 'pos-'.$tag, 'nombre' => 'Caja prueba']);
    $turno = TurnoCaja::create(['caja_id' => $caja->id, 'usuario_id' => $actor->id, 'abierta_at' => now()]);
    $dir = storage_path('framework/testing/pos-'.$tag);
    if (competirPos([['abrir', $mesa->id, (string) Str::uuid()], ['abrir', $mesa->id, (string) Str::uuid()]], $actor->id, $dir) !== [0, 2] || Pedido::where('creado_por', $actor->id)->count() !== 1 || OcupacionMesa::where('mesa_id', $mesa->id)->count() !== 1) {
        throw new RuntimeException('Dos aperturas ocuparon la misma mesa.');
    }
    $uno = Pedido::where('creado_por', $actor->id)->firstOrFail();
    if (competirPos([['descartar', $uno->id, (string) Str::uuid()], ['descartar', $uno->id, (string) Str::uuid()]], $actor->id, $dir) !== [0, 0] || OcupacionMesa::where('mesa_id', $mesa->id)->count() !== 0) {
        throw new RuntimeException('El descarte repetido no liberó una sola atención.');
    }
    $clave = (string) Str::uuid();
    if (competirPos([['abrir', $mesa->id, $clave], ['abrir', $mesa->id, $clave]], $actor->id, $dir) !== [0, 0] || Pedido::where('creado_por', $actor->id)->count() !== 2 || OcupacionMesa::where('mesa_id', $mesa->id)->count() !== 1) {
        throw new RuntimeException('La apertura repetida duplicó el pedido.');
    }
    $dos = Pedido::where('clave_registro', $clave)->firstOrFail();
    if (competirPos([['guardar', $dos->id, (string) Str::uuid()], ['guardar', $dos->id, (string) Str::uuid()]], $actor->id, $dir) !== [0, 2] || $dos->fresh()->observaciones !== 'Edición simultánea') {
        throw new RuntimeException('Se sobrescribió un borrador simultáneo.');
    }
    echo json_encode(['result' => 'passed', 'checks' => ['competing table openings', 'duplicate discard', 'duplicate opening', 'stale concurrent draft']], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if ($actor) {
        $pedidos = Pedido::where('creado_por', $actor->id)->pluck('id')->all();
    }
    DB::table('ocupaciones_mesa')->whereIn('pedido_id', $pedidos)->delete();
    DB::table('pedido_detalles')->whereIn('pedido_id', $pedidos)->delete();
    DB::table('pedidos')->whereIn('id', $pedidos)->delete();
    if ($turno) {
        DB::table('turnos_caja')->where('id', $turno->id)->delete();
    }
    if ($caja) {
        DB::table('cajas')->where('id', $caja->id)->delete();
    }
    if ($mesa) {
        DB::table('mesas')->where('id', $mesa->id)->delete();
    }
    if ($salon) {
        DB::table('salones')->where('id', $salon->id)->delete();
    }
    if ($actor) {
        DB::table('activity_log')->where('causer_type', User::class)->where('causer_id', $actor->id)->delete();
        DB::table('activity_log')->where('subject_type', User::class)->where('subject_id', $actor->id)->delete();
        DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $actor->id)->delete();
        DB::table('users')->where('id', $actor->id)->delete();
    }
}
