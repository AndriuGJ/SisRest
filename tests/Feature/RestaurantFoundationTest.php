<?php

use App\Enums\EstadoTurnoCaja;
use App\Models\Articulo;
use App\Models\Caja;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\Impresora;
use App\Models\Producto;
use App\Models\TurnoCaja;
use App\Models\UnidadMedida;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\ConfiguracionInicialSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EjemploInventarioSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

test('initial seed can be repeated without resetting credentials permissions or configuration', function () {
    config(['restaurante.administrador.password' => 'Una-clave-inicial-123']);
    $this->seed(DatabaseSeeder::class);
    $admin = User::query()->where('username', 'admin')->firstOrFail();
    $hash = $admin->password;
    $cajero = Role::findByName('cajero', 'web');
    $cajero->givePermissionTo('descuentos.aplicar');
    Empresa::query()->firstOrFail()->update(['nombre_comercial' => 'Restaurante configurado']);
    Impresora::query()->where('codigo', 'caja_1')->update(['host' => '192.168.1.90']);

    config(['restaurante.administrador.password' => 'Otra-clave-diferente-123']);
    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(1)
        ->and(User::firstOrFail()->password)->toBe($hash)
        ->and(Hash::check('Una-clave-inicial-123', $hash))->toBeTrue()
        ->and($admin->fresh()->can('usuarios.gestionar'))->toBeTrue()
        ->and(Role::findByName('cajero', 'web')->hasPermissionTo('descuentos.aplicar'))->toBeTrue()
        ->and(Caja::count())->toBe(2)
        ->and(Empresa::firstOrFail()->nombre_comercial)->toBe('Restaurante configurado')
        ->and(Impresora::query()->where('codigo', 'caja_1')->value('host'))->toBe('192.168.1.90')
        ->and(ConfiguracionFacturacion::firstOrFail()->habilitada)->toBeFalse()
        ->and(ConfiguracionFacturacion::firstOrFail()->tasa_impuesto)->toBeNull()
        ->and(DB::table('pedidos')->count())->toBe(0)
        ->and(DB::table('turnos_caja')->count())->toBe(0);
});

test('initial administrator requires a configured password and never uses a shared default', function () {
    config(['restaurante.administrador.password' => null]);

    expect(fn () => $this->seed(DatabaseSeeder::class))
        ->toThrow(RuntimeException::class, 'ADMIN_PASSWORD');
    expect(User::count())->toBe(0);
});

test('default staff roles do not receive privileged money or user administration actions', function () {
    $this->seed(RolesPermisosSeeder::class);
    $mozo = User::factory()->create();
    $mozo->assignRole('mozo');
    $cajero = User::factory()->create();
    $cajero->assignRole('cajero');
    $cocina = User::factory()->create();
    $cocina->assignRole('cocina');

    expect($mozo->can('pedidos.enviar'))->toBeTrue()
        ->and($mozo->can('cobros.crear'))->toBeFalse()
        ->and($cajero->can('cobros.crear'))->toBeTrue()
        ->and($cajero->can('cajas.retirar'))->toBeFalse()
        ->and($cajero->can('usuarios.gestionar'))->toBeFalse()
        ->and($cocina->can('cocina.marcar_listo'))->toBeTrue()
        ->and($cocina->can('reportes.ver_todos'))->toBeFalse();
});

test('inventory examples are repeatable and do not fabricate purchases or stock', function () {
    $this->seed(EjemploInventarioSeeder::class);
    $this->seed(EjemploInventarioSeeder::class);
    $combo = Producto::query()->where('codigo', 'EJ-COMBO')->firstOrFail();
    $chaufa = Producto::query()->where('codigo', 'EJ-CHAUFA')->firstOrFail();

    expect($combo->articulo_id)->toBeNull()
        ->and($combo->componentes()->count())->toBe(2)
        ->and($chaufa->receta->detalles()->count())->toBe(3)
        ->and(bccomp((string) Articulo::query()->sum('stock_actual'), '0', 4))->toBe(0)
        ->and(DB::table('compras')->count())->toBe(0)
        ->and(DB::table('preparaciones')->count())->toBe(0);
});

test('two boxes may be open but one box cannot have two simultaneous open shifts', function () {
    $this->seed([CatalogosSeeder::class, ConfiguracionInicialSeeder::class]);
    $usuario = User::factory()->create();
    $cajas = Caja::query()->orderBy('id')->get();
    $datos = ['usuario_id' => $usuario->id, 'fondo_inicial' => '100', 'abierta_at' => now()];
    $primero = TurnoCaja::create($datos + ['caja_id' => $cajas[0]->id]);
    TurnoCaja::create($datos + ['caja_id' => $cajas[1]->id]);

    expect(fn () => TurnoCaja::create($datos + ['caja_id' => $cajas[0]->id]))
        ->toThrow(QueryException::class);

    $primero->update(['estado' => EstadoTurnoCaja::CERRADA, 'cerrada_at' => now(), 'cerrada_por' => $usuario->id]);
    TurnoCaja::create($datos + ['caja_id' => $cajas[0]->id]);
    expect(TurnoCaja::query()->where('estado', 'abierta')->count())->toBe(2);
});

test('cash balance includes only cash movements and is independent for each shift', function () {
    $this->seed([CatalogosSeeder::class, ConfiguracionInicialSeeder::class]);
    $usuario = User::factory()->create();
    $caja = Caja::firstOrFail();
    $turno = TurnoCaja::create([
        'caja_id' => $caja->id, 'usuario_id' => $usuario->id,
        'fondo_inicial' => '100.00', 'abierta_at' => now(),
    ]);

    foreach ([
        ['tipo' => 'ingreso', 'monto' => '20.00', 'concepto' => 'Venta: efectivo neto'],
        ['tipo' => 'egreso', 'monto' => '10.50', 'concepto' => 'Compra con efectivo'],
        ['tipo' => 'retiro', 'monto' => '5.00', 'concepto' => 'Retiro del responsable'],
    ] as $i => $datos) {
        $turno->movimientos()->create($datos + [
            'usuario_id' => $usuario->id, 'clave_idempotencia' => 'prueba-caja-'.$i, 'registrado_at' => now(),
        ]);
    }

    expect($turno->efectivoEsperado())->toBe('104.50');
    expect(fn () => $turno->movimientos()->create([
        'tipo' => 'ingreso', 'monto' => '20', 'concepto' => 'Reintento',
        'usuario_id' => $usuario->id, 'clave_idempotencia' => 'prueba-caja-0', 'registrado_at' => now(),
    ]))->toThrow(QueryException::class);
    expect($turno->efectivoEsperado())->toBe('104.50');
});

test('available stock excludes reservations without consuming ingredients twice', function () {
    $this->seed(CatalogosSeeder::class);
    $unidad = UnidadMedida::query()->where('codigo', 'porcion')->firstOrFail();
    $articulo = Articulo::create([
        'codigo' => 'CHAUFA', 'nombre' => 'Chaufa preparado', 'tipo' => 'preparado',
        'unidad_medida_id' => $unidad->id, 'stock_actual' => '10', 'stock_reservado' => '3',
    ]);

    expect($articulo->disponible)->toBe('7.0000')
        ->and($articulo->stock_actual)->toBe('10.0000');
});

test('fiscal secrets are encrypted hidden and excluded from audit attributes', function () {
    $this->seed([CatalogosSeeder::class, ConfiguracionInicialSeeder::class]);
    $configuracion = ConfiguracionFacturacion::firstOrFail();
    $antes = Activity::query()->where('subject_type', ConfiguracionFacturacion::class)->count();
    $configuracion->update(['usuario_sol' => 'usuario-prueba', 'clave_sol' => 'clave-prueba']);

    expect($configuracion->fresh()->clave_sol)->toBe('clave-prueba')
        ->and($configuracion->toArray())->not->toHaveKey('clave_sol')
        ->and(DB::table('configuraciones_facturacion')->value('clave_sol'))->not->toBe('clave-prueba')
        ->and(Activity::query()->where('subject_type', ConfiguracionFacturacion::class)->count())->toBe($antes);
});

test('mysql rejects reservations larger than physical stock', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Las restricciones CHECK adicionales se verifican en MySQL.');
    }
    $this->seed(CatalogosSeeder::class);
    $unidad = UnidadMedida::query()->where('codigo', 'porcion')->firstOrFail();

    expect(fn () => Articulo::create([
        'codigo' => 'IMPOSIBLE', 'nombre' => 'Stock inválido', 'tipo' => 'preparado',
        'unidad_medida_id' => $unidad->id, 'stock_actual' => '1', 'stock_reservado' => '2',
    ]))->toThrow(QueryException::class);
});
