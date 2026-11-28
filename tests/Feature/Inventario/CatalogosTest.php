<?php

use App\Livewire\Inventario\InsumoFormulario;
use App\Livewire\Inventario\Insumos;
use App\Livewire\Inventario\ProductoFormulario;
use App\Livewire\Inventario\Productos;
use App\Models\AreaPreparacion;
use App\Models\Articulo;
use App\Models\CategoriaProducto;
use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Inventario\Disponibilidad;
use App\Services\Inventario\GestionInsumos;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\ConfiguracionInicialSeeder;
use Database\Seeders\EjemploInventarioSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed([RolesPermisosSeeder::class, CatalogosSeeder::class, ConfiguracionInicialSeeder::class]);
    $this->administrador = User::factory()->create();
    $this->administrador->assignRole('administrador');
    $this->actingAs($this->administrador);
    $this->gramos = UnidadMedida::where('codigo', 'g')->firstOrFail();
    $this->porcion = UnidadMedida::where('codigo', 'porcion')->firstOrFail();
    $this->categoria = CategoriaProducto::where('nombre', 'Platos')->firstOrFail();
});

function formularioPlato(): Testable
{
    return Livewire::test(ProductoFormulario::class)->set('codigo', 'CHAUFA')->set('nombre', 'Chaufa de pollo')
        ->set('categoriaId', (string) CategoriaProducto::where('nombre', 'Platos')->value('id'))->set('precio', '20.50');
}

test('inventory routes distinguish consultation creation and editing permissions', function () {
    auth()->logout();
    $this->get(route('insumos.index'))->assertRedirect(route('login'));
    $this->get(route('productos.index'))->assertRedirect(route('login'));
    $lector = User::factory()->create();
    $lector->givePermissionTo(['productos.ver', 'inventario.ver']);
    $this->actingAs($lector);
    $this->get(route('insumos.index'))->assertOk()->assertDontSee('Nuevo insumo');
    $this->get(route('productos.index'))->assertOk()->assertDontSee('Nuevo producto');
    $this->get(route('insumos.crear'))->assertForbidden();
    $this->get(route('productos.crear'))->assertForbidden();
    expect(fn () => app(GestionInsumos::class)->guardar($lector, null, [], []))->toThrow(AuthorizationException::class);
    $lector->revokePermissionTo('inventario.ver');
    $this->get(route('insumos.index'))->assertForbidden();
    $this->get(route('dashboard'))->assertDontSee('href="'.route('insumos.index').'"', false);
});

test('create ingredient with base unit minimum and purchase presentation without generating stock', function () {
    Livewire::test(InsumoFormulario::class)->set('codigo', ' arroz ')->set('nombre', '  Arroz  superior  ')
        ->set('unidadId', (string) $this->gramos->id)->set('minimo', '5000')
        ->set('presentaciones', ['saco' => ['id' => '', 'nombre' => 'Saco de 50 kg', 'factor' => '50000', 'activo' => true]])
        ->call('save')->assertHasNoErrors()->assertRedirect(route('insumos.index'));
    $insumo = Articulo::where('codigo', 'ARROZ')->firstOrFail();
    expect($insumo->nombre)->toBe('Arroz superior')->and($insumo->stock_actual)->toBe('0.0000')
        ->and($insumo->stock_minimo)->toBe('5000.0000')->and($insumo->presentaciones->first()->factor_conversion)->toBe('50000.0000')
        ->and(DB::table('movimientos_inventario')->count())->toBe(0);
    Livewire::test(Insumos::class)->set('stockFilter', 'bajo')->assertSee('Arroz superior')->assertSee('Bajo el mínimo');
    expect(Activity::where('event', 'insumo_configurado')->count())->toBe(1);
});

test('ingredient updates preserve balances costs codes units and soft deactivate presentations', function () {
    $insumo = Articulo::create(['codigo' => 'ARROZ', 'nombre' => 'Arroz', 'tipo' => 'insumo', 'unidad_medida_id' => $this->gramos->id,
        'stock_actual' => '25000', 'stock_reservado' => '0', 'costo_promedio' => '0.005']);
    $presentacion = $insumo->presentaciones()->create(['nombre' => 'saco', 'factor_conversion' => '50000']);
    Livewire::test(InsumoFormulario::class, ['insumo' => $insumo])->set('nombre', 'Arroz premium')->set('minimo', '10000')
        ->set('presentaciones.p-'.$presentacion->id.'.activo', false)->call('save')->assertHasNoErrors();
    expect($insumo->fresh()->stock_actual)->toBe('25000.0000')->and($insumo->fresh()->costo_promedio)->toBe('0.005000')
        ->and($presentacion->fresh()->activo)->toBeFalse();
    Livewire::test(InsumoFormulario::class, ['insumo' => $insumo])->set('codigo', 'OTRO')->call('save')->assertHasErrors('seguridad');
    Livewire::test(InsumoFormulario::class, ['insumo' => $insumo])->set('unidadId', (string) $this->porcion->id)->call('save')->assertHasErrors('seguridad');
});

test('presentations reject foreign identifiers duplicates invalid decimals and rollback metadata on error', function () {
    $insumo = Articulo::create(['codigo' => 'UNO', 'nombre' => 'Uno', 'tipo' => 'insumo', 'unidad_medida_id' => $this->gramos->id]);
    $otro = Articulo::create(['codigo' => 'DOS', 'nombre' => 'Dos', 'tipo' => 'insumo', 'unidad_medida_id' => $this->gramos->id]);
    $ajena = $otro->presentaciones()->create(['nombre' => 'saco', 'factor_conversion' => 50]);
    Livewire::test(InsumoFormulario::class, ['insumo' => $insumo])->set('nombre', 'No guardar')
        ->set('presentaciones', ['x' => ['id' => (string) $ajena->id, 'nombre' => 'robada', 'factor' => '1', 'activo' => true]])
        ->call('save')->assertHasErrors('presentaciones.x.id');
    expect($insumo->fresh()->nombre)->toBe('Uno')->and($ajena->fresh()->nombre)->toBe('saco');
    Livewire::test(InsumoFormulario::class, ['insumo' => $insumo])->set('presentaciones', [
        'a' => ['id' => '', 'nombre' => 'Caja', 'factor' => '2', 'activo' => true],
        'b' => ['id' => '', 'nombre' => ' caja ', 'factor' => '3', 'activo' => true],
    ])->call('save')->assertHasErrors('presentaciones.b.nombre');
    Livewire::test(InsumoFormulario::class, ['insumo' => $insumo])->set('minimo', '-1')
        ->set('presentaciones', ['x' => ['id' => '', 'nombre' => 'saco', 'factor' => '1e5', 'activo' => true]])
        ->call('save')->assertHasErrors('minimo');
    expect($insumo->presentaciones()->count())->toBe(0);
});

test('presentations already used by a purchase preserve conversion factors', function () {
    $insumo = Articulo::create(['codigo' => 'ARROZ', 'nombre' => 'Arroz', 'tipo' => 'insumo', 'unidad_medida_id' => $this->gramos->id]);
    $presentacion = $insumo->presentaciones()->create(['nombre' => 'saco', 'factor_conversion' => '50000']);
    $proveedor = Proveedor::create(['nombre' => 'Proveedor de prueba']);
    $compra = Compra::create(['codigo' => 'COMPRA-PRUEBA', 'proveedor_id' => $proveedor->id, 'usuario_id' => $this->administrador->id, 'fecha' => today(), 'moneda' => 'PEN']);
    CompraDetalle::create(['compra_id' => $compra->id, 'articulo_id' => $insumo->id, 'presentacion_articulo_id' => $presentacion->id,
        'descripcion' => 'Saco', 'cantidad' => 1, 'factor_conversion' => 50000, 'cantidad_base' => 50000, 'costo_unitario' => 100, 'total' => 100]);
    Livewire::test(InsumoFormulario::class, ['insumo' => $insumo])->set('presentaciones.p-'.$presentacion->id.'.factor', '25000')
        ->call('save')->assertHasErrors('presentaciones.p-'.$presentacion->id.'.factor');
    expect($presentacion->fresh()->factor_conversion)->toBe('50000.0000');
});

test('prepared product automatically owns one inventory article without making ingredients or stock', function () {
    formularioPlato()->call('save')->assertHasNoErrors()->assertRedirect(route('productos.index'));
    $producto = Producto::where('codigo', 'CHAUFA')->firstOrFail();
    expect($producto->articulo->tipo->value)->toBe('preparado')->and($producto->articulo->stock_actual)->toBe('0.0000')
        ->and($producto->precio_venta)->toBe('20.50')->and($producto->articulo->unidad_medida_id)->toBe($this->porcion->id)
        ->and(Articulo::count())->toBe(1)->and(DB::table('movimientos_inventario')->count())->toBe(0)
        ->and(DB::table('preparaciones')->count())->toBe(0);
    Livewire::test(Productos::class)->assertSee('Chaufa de pollo')->assertSee('Sin receta');
});

test('reventa and beverages can use purchased units and presentations', function () {
    formularioPlato()->set('tipo', 'reventa')->set('nombre', 'Gaseosa botella')->set('destinoId', '')
        ->set('presentaciones', ['caja' => ['id' => '', 'nombre' => 'Caja de 12', 'factor' => '12', 'activo' => true]])
        ->call('save')->assertHasNoErrors();
    $producto = Producto::where('codigo', 'CHAUFA')->firstOrFail();
    expect($producto->articulo->tipo->value)->toBe('reventa')->and($producto->area_preparacion_id)->toBeNull()
        ->and($producto->articulo->presentaciones->first()->factor_conversion)->toBe('12.0000');
    formularioPlato()->set('codigo', 'BEBIDA')->set('tipo', 'bebida')->set('origen', 'reventa')
        ->set('unidadId', (string) UnidadMedida::where('codigo', 'und')->value('id'))->call('save')->assertHasNoErrors();
});

test('product validates price origin active destination and active category', function () {
    formularioPlato()->set('precio', '20.999')->call('save')->assertHasErrors('precio');
    formularioPlato()->set('origen', 'reventa')->call('save')->assertHasErrors('origen');
    $barra = AreaPreparacion::where('codigo', 'barra')->firstOrFail();
    formularioPlato()->set('destinoId', (string) $barra->id)->call('save')->assertHasErrors('destinoId');
    $this->categoria->update(['activo' => false]);
    formularioPlato()->call('save')->assertHasErrors('categoriaId');
    expect(Producto::count())->toBe(0)->and(Articulo::count())->toBe(0);
});

test('updating prices and visibility preserves stock reservations cost and inventory identity', function () {
    formularioPlato()->call('save')->assertHasNoErrors();
    $producto = Producto::firstOrFail();
    $producto->articulo->update(['stock_actual' => 10, 'stock_reservado' => 3, 'costo_promedio' => '8.123456']);
    $articuloId = $producto->articulo_id;
    Livewire::test(ProductoFormulario::class, ['producto' => $producto])->set('precio', '25')->set('visible', false)->set('nombre', 'Chaufa especial')
        ->call('save')->assertHasNoErrors();
    expect($producto->fresh()->articulo_id)->toBe($articuloId)->and($producto->fresh()->articulo->stock_actual)->toBe('10.0000')
        ->and($producto->fresh()->articulo->stock_reservado)->toBe('3.0000')->and($producto->fresh()->articulo->costo_promedio)->toBe('8.123456');
    Livewire::test(ProductoFormulario::class, ['producto' => $producto->fresh()])->set('tipo', 'combo')->call('save')->assertHasErrors('seguridad');
    Livewire::test(ProductoFormulario::class, ['producto' => $producto->fresh()])->set('origen', 'reventa')->call('save')->assertHasErrors('origen');
    expect(Articulo::count())->toBe(1)->and(DB::table('movimientos_inventario')->count())->toBe(0);
});

test('combo has no stock article and available portions reflect component reservations', function () {
    $this->seed(EjemploInventarioSeeder::class);
    $chaufa = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    $gaseosa = Producto::where('codigo', 'EJ-GASEOSA')->firstOrFail();
    $chaufa->articulo->update(['stock_actual' => 10, 'stock_reservado' => 3]);
    $gaseosa->articulo->update(['stock_actual' => 8, 'stock_reservado' => 0]);
    formularioPlato()->set('codigo', 'COMBO-1')->set('tipo', 'combo')->set('nombre', 'Combo familiar')
        ->set('componentes', ['a' => ['productoId' => (string) $chaufa->id, 'cantidad' => '2'], 'b' => ['productoId' => (string) $gaseosa->id, 'cantidad' => '1']])
        ->call('save')->assertHasNoErrors();
    $combo = Producto::where('codigo', 'COMBO-1')->firstOrFail();
    expect($combo->articulo_id)->toBeNull()->and($combo->componentes()->count())->toBe(2)
        ->and(app(Disponibilidad::class)->consultar($combo))->toBe('3')
        ->and($chaufa->articulo->fresh()->stock_actual)->toBe('10.0000');
});

test('active combos reject missing duplicated inactive or nested components', function () {
    $this->seed(EjemploInventarioSeeder::class);
    $chaufa = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    $combo = Producto::where('codigo', 'EJ-COMBO')->firstOrFail();
    formularioPlato()->set('tipo', 'combo')->call('save')->assertHasErrors('componentes');
    formularioPlato()->set('tipo', 'combo')->set('componentes', ['x' => ['productoId' => (string) $combo->id, 'cantidad' => '1']])
        ->call('save')->assertHasErrors('componentes.x.productoId');
    formularioPlato()->set('tipo', 'combo')->set('componentes', ['a' => ['productoId' => (string) $chaufa->id, 'cantidad' => '1'], 'b' => ['productoId' => (string) $chaufa->id, 'cantidad' => '1']])
        ->call('save')->assertHasErrors('componentes.a.productoId');
    $chaufa->update(['activo' => false]);
    formularioPlato()->set('tipo', 'combo')->set('componentes', ['x' => ['productoId' => (string) $chaufa->id, 'cantidad' => '1']])
        ->call('save')->assertHasErrors('componentes.x.productoId');
});

test('deactivation is confirmed and preserves stock while protecting reservations active combos recipes and orders', function () {
    $this->seed(EjemploInventarioSeeder::class);
    $insumo = Articulo::where('codigo', 'EJ-ARROZ')->firstOrFail();
    Livewire::test(Insumos::class)->call('requestStateChange', $insumo->id)->call('confirmState')->assertHasErrors('seguridad');
    $chaufa = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    $combo = Producto::where('codigo', 'EJ-COMBO')->firstOrFail();
    Livewire::test(Productos::class)->call('requestStateChange', $chaufa->id)->call('confirmState')->assertHasErrors('seguridad');
    $combo->update(['activo' => false]);
    $chaufa->articulo->update(['stock_actual' => 10, 'stock_reservado' => 1]);
    Livewire::test(Productos::class)->call('requestStateChange', $chaufa->id)->call('confirmState')->assertHasErrors('seguridad');
    $chaufa->articulo->update(['stock_reservado' => 0]);
    $pedido = Pedido::create(['codigo' => 'PED-1', 'modalidad' => 'rapida', 'creado_por' => $this->administrador->id, 'abierto_at' => now()]);
    PedidoDetalle::create(['pedido_id' => $pedido->id, 'producto_id' => $chaufa->id, 'descripcion' => 'Chaufa', 'cantidad' => 1]);
    Livewire::test(Productos::class)->call('requestStateChange', $chaufa->id)->call('confirmState')->assertHasErrors('seguridad');
    $pedido->update(['estado' => 'cerrado']);
    $form = Livewire::test(Productos::class)->call('requestStateChange', $chaufa->id)->assertDispatched('app:confirm');
    expect($chaufa->fresh()->activo)->toBeTrue();
    $form->call('confirmState')->assertHasNoErrors();
    expect($chaufa->fresh()->activo)->toBeFalse()->and($chaufa->articulo->fresh()->stock_actual)->toBe('10.0000');
});

test('photos can be replaced and removed without leaving obsolete files', function () {
    Storage::fake('public');
    formularioPlato()->set('foto', UploadedFile::fake()->image('plato.jpg'))->call('save')->assertHasNoErrors();
    $producto = Producto::where('codigo', 'CHAUFA')->firstOrFail();
    $primera = $producto->imagen_ruta;
    Storage::disk('public')->assertExists($primera);
    Livewire::test(ProductoFormulario::class, ['producto' => $producto])->set('foto', UploadedFile::fake()->image('nuevo.png'))->call('save')->assertHasNoErrors();
    Storage::disk('public')->assertMissing($primera);
    $segunda = $producto->fresh()->imagen_ruta;
    Storage::disk('public')->assertExists($segunda);
    Livewire::test(ProductoFormulario::class, ['producto' => $producto->fresh()])->set('quitarFoto', true)->call('save')->assertHasNoErrors();
    Storage::disk('public')->assertMissing($segunda);
    expect($producto->fresh()->imagen_ruta)->toBeNull();
});

test('invalid photo or invalid components leave no files or partial stock articles', function () {
    Storage::fake('public');
    formularioPlato()->set('foto', UploadedFile::fake()->create('malicioso.svg', 1, 'image/svg+xml'))->call('save')->assertHasErrors('foto');
    formularioPlato()->set('tipo', 'combo')->set('foto', UploadedFile::fake()->image('combo.png'))
        ->set('componentes', ['x' => ['productoId' => '999999', 'cantidad' => '1']])->call('save')->assertHasErrors('componentes.x.productoId');
    expect(Storage::disk('public')->allFiles('productos'))->toBe([])->and(Producto::count())->toBe(0)->and(Articulo::count())->toBe(0);
});

test('search filters and pagination work and sensitive form identifiers remain locked', function () {
    $this->seed(EjemploInventarioSeeder::class);
    Livewire::test(Insumos::class)->set('search', 'arroz')->assertSee('Arroz (ejemplo)')->assertDontSee('Pollo (ejemplo)');
    Livewire::test(Productos::class)->set('typeFilter', 'combo')->assertSee('Chaufa y gaseosa (ejemplo)')->assertDontSee('Editar producto Chaufa (ejemplo)');
    Livewire::test(Productos::class)->call('gotoPage', 2)->set('search', 'EJ-CHAUFA')->assertSet('paginators.page', 1)->assertSee('Chaufa (ejemplo)');
    expect(fn () => Livewire::test(InsumoFormulario::class)->set('insumoId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(ProductoFormulario::class)->set('productoId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
    $formulario = formularioPlato();
    $this->administrador->roles->first()->revokePermissionTo('productos.crear');
    $this->administrador->unsetRelations();
    $formulario->call('save')->assertForbidden();
});

test('new ingredient permissions upgrade existing inventory managers once and seeders preserve later revocations', function () {
    Permission::whereIn('name', ['insumos.crear', 'insumos.editar'])->delete();
    $gestor = Role::create(['name' => 'inventario_existente', 'guard_name' => 'web']);
    $gestor->givePermissionTo('inventario.ajustar');
    $lector = Role::create(['name' => 'lector_existente', 'guard_name' => 'web']);
    $lector->givePermissionTo('inventario.ver');
    $migracion = require database_path('migrations/2026_11_18_000059_add_insumo_catalog_permissions.php');
    $migracion->up();
    expect($gestor->fresh()->hasPermissionTo('insumos.crear'))->toBeTrue()->and($lector->fresh()->hasPermissionTo('insumos.crear'))->toBeFalse();
    $gestor->revokePermissionTo('insumos.editar');
    $this->seed(RolesPermisosSeeder::class);
    expect($gestor->fresh()->hasPermissionTo('insumos.editar'))->toBeFalse();
});

test('presentation rows require their identity field and normalize existing mixed case names consistently', function () {
    $insumo = Articulo::create(['codigo' => 'ARROZ', 'nombre' => 'Arroz', 'tipo' => 'insumo', 'unidad_medida_id' => $this->gramos->id]);
    $insumo->presentaciones()->create(['nombre' => 'CAJÓN', 'factor_conversion' => 10]);
    Livewire::test(InsumoFormulario::class, ['insumo' => $insumo])->set('presentaciones', [
        'x' => ['id' => '', 'nombre' => 'cajón', 'factor' => '12', 'activo' => true],
    ])->call('save')->assertHasErrors('presentaciones.x.nombre');
    Livewire::test(InsumoFormulario::class, ['insumo' => $insumo])->set('presentaciones', [
        'x' => ['nombre' => 'caja', 'factor' => '12', 'activo' => true],
    ])->call('save')->assertHasErrors('presentaciones.x.id');
    expect($insumo->presentaciones()->count())->toBe(1);
});

test('editing combo composition keeps one set of components and never changes article balances', function () {
    $this->seed(EjemploInventarioSeeder::class);
    $combo = Producto::where('codigo', 'EJ-COMBO')->firstOrFail();
    $linea = $combo->componentes()->firstOrFail();
    $antes = Articulo::query()->pluck('stock_actual', 'id')->all();
    Livewire::test(ProductoFormulario::class, ['producto' => $combo])
        ->set('componentes.c-'.$linea->id.'.cantidad', '2')->set('precio', '30')
        ->call('save')->assertHasNoErrors();
    expect($combo->fresh()->componentes()->count())->toBe(2)
        ->and($combo->fresh()->componentes()->where('producto_id', $linea->producto_id)->value('cantidad'))->toBe('2.0000')
        ->and(Articulo::query()->pluck('stock_actual', 'id')->all())->toBe($antes);
});
