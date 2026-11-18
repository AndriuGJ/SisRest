<?php

use App\Enums\EstadoPreparacion;
use App\Livewire\Inventario\PreparacionDetalle as DetalleLivewire;
use App\Livewire\Inventario\Preparaciones;
use App\Livewire\Inventario\PreparacionFormulario;
use App\Livewire\Inventario\RecetaFormulario;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\Preparacion;
use App\Models\Producto;
use App\Models\User;
use App\Services\Inventario\CalculoProduccion;
use App\Services\Inventario\GestionPreparaciones;
use App\Services\Inventario\GestionRecetas;
use Database\Seeders\EjemploInventarioSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([RolesPermisosSeeder::class, EjemploInventarioSeeder::class]);
    $this->administrador = User::factory()->create();
    $this->administrador->assignRole('administrador');
    $this->actingAs($this->administrador);
    $this->producto = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    $this->arroz = Articulo::where('codigo', 'EJ-ARROZ')->firstOrFail();
    $this->pollo = Articulo::where('codigo', 'EJ-POLLO')->firstOrFail();
    $this->huevo = Articulo::where('codigo', 'EJ-HUEVO')->firstOrFail();
    $this->arroz->update(['stock_actual' => '5000', 'costo_promedio' => '0.006']);
    $this->pollo->update(['stock_actual' => '5000', 'costo_promedio' => '0.020']);
    $this->huevo->update(['stock_actual' => '50', 'costo_promedio' => '0.5']);
});

function borradorProduccion(User $actor, Producto $producto, string $cantidad = '10', array $reales = [], string $motivo = '', ?string $clave = null): Preparacion
{
    $servicio = app(GestionPreparaciones::class);
    $calculo = $servicio->previsualizar($actor, $producto->id, $cantidad);

    return $servicio->guardar($actor, null, $clave ?? (string) Str::uuid(), ['productoId' => (string) $producto->id, 'cantidad' => $cantidad,
        'fecha' => now()->format('Y-m-d'), 'observaciones' => '', 'motivoAjuste' => $motivo], $reales, $calculo['calculoHash'])->refresh();
}

function confirmarProduccion(User $actor, Preparacion $preparacion): Preparacion
{
    return app(GestionPreparaciones::class)->confirmar($actor, $preparacion->id, GestionPreparaciones::huellaBorrador($preparacion->fresh()));
}

test('recipe and preparation pages require the specific consultation and action permissions', function () {
    auth()->logout();
    $this->get(route('productos.receta', $this->producto))->assertRedirect(route('login'));
    $this->get(route('preparaciones.index'))->assertRedirect(route('login'));
    $lector = User::factory()->create();
    $lector->givePermissionTo(['preparaciones.ver', 'productos.ver']);
    $this->actingAs($lector);
    $this->get(route('preparaciones.index'))->assertOk()->assertDontSee('Nueva preparación');
    $this->get(route('preparaciones.crear'))->assertForbidden();
    $this->get(route('productos.receta', $this->producto))->assertForbidden();
    expect(fn () => app(GestionPreparaciones::class)->previsualizar($lector, $this->producto->id, '10'))->toThrow(AuthorizationException::class);
    $lector->givePermissionTo('recetas.gestionar');
    $this->get(route('productos.receta', $this->producto))->assertOk();
});

test('recipe scales consumption to requested portions without touching stock or creating movements', function () {
    $antes = Articulo::pluck('stock_actual', 'id')->all();
    $calculo = app(GestionPreparaciones::class)->previsualizar($this->administrador, $this->producto->id, '10');
    expect($calculo['ingredientes'][$this->arroz->id]['estandar'])->toBe('1800.0000')
        ->and($calculo['ingredientes'][$this->pollo->id]['estandar'])->toBe('1200.0000')
        ->and($calculo['ingredientes'][$this->huevo->id]['estandar'])->toBe('10.0000')
        ->and(Articulo::pluck('stock_actual', 'id')->all())->toBe($antes)->and(MovimientoInventario::count())->toBe(0);
    $receta = $this->producto->receta;
    Livewire::test(RecetaFormulario::class, ['producto' => $this->producto])->set('rendimiento', '2')->call('save')->assertHasNoErrors();
    expect($receta->fresh()->rendimiento)->toBe('2.0000')->and(MovimientoInventario::count())->toBe(0);
});

test('recipes reject duplicate self and inactive ingredients and reventa products', function () {
    $componente = Livewire::test(RecetaFormulario::class, ['producto' => $this->producto]);
    $componente->set('ingredientes', ['x' => ['articuloId' => (string) $this->producto->articulo_id, 'cantidad' => '1']])
        ->call('save')->assertHasErrors('ingredientes.x.articuloId');
    Livewire::test(RecetaFormulario::class, ['producto' => $this->producto])->set('ingredientes', [
        'a' => ['articuloId' => (string) $this->arroz->id, 'cantidad' => '1'], 'b' => ['articuloId' => (string) $this->arroz->id, 'cantidad' => '2'],
    ])->call('save')->assertHasErrors('ingredientes.a.articuloId');
    $this->arroz->update(['activo' => false]);
    Livewire::test(RecetaFormulario::class, ['producto' => $this->producto])->call('save')->assertHasErrors();
    $reventa = Producto::where('codigo', 'EJ-GASEOSA')->firstOrFail();
    expect(fn () => app(GestionRecetas::class)->guardar($this->administrador, $reventa->id, '1', '', true, [], ''))->toThrow(ValidationException::class);
});

test('indirect recipe cycles are blocked and existing recipes are preserved', function () {
    $intermedio = Producto::where('codigo', 'EJ-EXTRA')->firstOrFail();
    // El ejemplo usa un preparado para el adicional.
    $intermedio->articulo->update(['tipo' => 'preparado']);
    $gestion = app(GestionRecetas::class);
    $gestion->guardar($this->administrador, $intermedio->id, '1', '', true,
        ['a' => ['articuloId' => (string) $this->producto->articulo_id, 'cantidad' => '1']], '');
    $receta = $this->producto->receta;
    expect(fn () => $gestion->guardar($this->administrador, $this->producto->id, '1', '', true,
        ['a' => ['articuloId' => (string) $intermedio->articulo_id, 'cantidad' => '1']], GestionRecetas::huella($receta)))
        ->toThrow(ValidationException::class);
    expect($receta->fresh()->detalles()->count())->toBe(3);
});

test('concurrent recipe editors cannot silently overwrite newer definitions', function () {
    $primero = Livewire::test(RecetaFormulario::class, ['producto' => $this->producto]);
    $segundo = Livewire::test(RecetaFormulario::class, ['producto' => $this->producto]);
    $primero->set('observaciones', 'Nueva definición')->call('save')->assertHasNoErrors();
    $segundo->set('observaciones', 'Definición antigua')->call('save')->assertHasErrors('seguridad');
    expect($this->producto->receta->fresh()->observaciones)->toBe('Nueva definición');
});

test('saving drafts is idempotent and does not alter ingredient stock or cost averages', function () {
    $antes = Articulo::get()->mapWithKeys(fn (Articulo $a) => [$a->id => [$a->stock_actual, $a->costo_promedio]])->all();
    $clave = (string) Str::uuid();
    $primera = borradorProduccion($this->administrador, $this->producto, clave: $clave);
    $segunda = borradorProduccion($this->administrador, $this->producto, clave: $clave);
    expect($segunda->id)->toBe($primera->id)->and(Preparacion::count())->toBe(1)
        ->and(MovimientoInventario::count())->toBe(0)->and($primera->detalles()->count())->toBe(3)
        ->and(Articulo::get()->mapWithKeys(fn (Articulo $a) => [$a->id => [$a->stock_actual, $a->costo_promedio]])->all())->toBe($antes);
});

test('confirmation consumes ingredients produces stock and preserves reservations with weighted output cost', function () {
    $this->producto->articulo->update(['stock_actual' => '5', 'stock_reservado' => '2', 'costo_promedio' => '10']);
    $preparacion = borradorProduccion($this->administrador, $this->producto);
    $resultado = confirmarProduccion($this->administrador, $preparacion);
    expect($resultado->estado)->toBe(EstadoPreparacion::CONFIRMADA)->and($resultado->confirmada_por)->toBe($this->administrador->id)
        ->and($resultado->costo_total)->toBe('39.800000')->and($this->arroz->fresh()->stock_actual)->toBe('3200.0000')
        ->and($this->pollo->fresh()->stock_actual)->toBe('3800.0000')->and($this->huevo->fresh()->stock_actual)->toBe('40.0000')
        ->and($this->producto->articulo->fresh()->stock_actual)->toBe('15.0000')->and($this->producto->articulo->fresh()->stock_reservado)->toBe('2.0000')
        ->and($this->producto->articulo->fresh()->costo_promedio)->toBe('5.986667')->and(MovimientoInventario::count())->toBe(4);
    $movimiento = MovimientoInventario::where('articulo_id', $this->arroz->id)->firstOrFail();
    expect($movimiento->cantidad)->toBe('-1800.0000')->and($movimiento->saldo_anterior)->toBe('5000.0000')
        ->and($movimiento->saldo_posterior)->toBe('3200.0000')->and($movimiento->origen_id)->toBe($preparacion->id);
});

test('repeated confirmations do not duplicate movements or overwrite the original confirmer', function () {
    $preparacion = borradorProduccion($this->administrador, $this->producto);
    $version = GestionPreparaciones::huellaBorrador($preparacion);
    $gestion = app(GestionPreparaciones::class);
    $gestion->confirmar($this->administrador, $preparacion->id, $version);
    $otro = User::factory()->create();
    $otro->givePermissionTo('preparaciones.confirmar');
    $gestion->confirmar($otro, $preparacion->id, $version);
    expect(MovimientoInventario::count())->toBe(4)->and($this->producto->articulo->fresh()->stock_actual)->toBe('10.0000')
        ->and($preparacion->fresh()->confirmada_por)->toBe($this->administrador->id)
        ->and(Activity::where('event', 'preparacion_confirmada')->count())->toBe(1);
});

test('insufficient or reserved ingredients reject the whole confirmation without partial movements', function (bool $reservado) {
    $preparacion = borradorProduccion($this->administrador, $this->producto);
    if ($reservado) {
        $this->arroz->update(['stock_actual' => '1800', 'stock_reservado' => '1']);
    } else {
        $this->huevo->update(['stock_actual' => '0']);
    }
    $antes = Articulo::pluck('stock_actual', 'id')->all();
    expect(fn () => confirmarProduccion($this->administrador, $preparacion))->toThrow(ValidationException::class);
    expect(MovimientoInventario::count())->toBe(0)->and(Articulo::pluck('stock_actual', 'id')->all())->toBe($antes)
        ->and($preparacion->fresh()->estado)->toBe(EstadoPreparacion::BORRADOR);
})->with([false, true]);

test('two drafts compete for the same stock and only one can finish', function () {
    $this->arroz->update(['stock_actual' => '1800']);
    $primera = borradorProduccion($this->administrador, $this->producto);
    $segunda = borradorProduccion($this->administrador, $this->producto);
    confirmarProduccion($this->administrador, $primera);
    expect(fn () => confirmarProduccion($this->administrador, $segunda))->toThrow(ValidationException::class);
    expect($this->arroz->fresh()->stock_actual)->toBe('0.0000')->and(MovimientoInventario::count())->toBe(4)
        ->and($segunda->fresh()->estado)->toBe(EstadoPreparacion::BORRADOR);
});

test('consumption adjustments require permission and reason and never change the recipe', function () {
    $usuario = User::factory()->create();
    $usuario->givePermissionTo(['preparaciones.crear', 'preparaciones.confirmar']);
    $calculo = app(GestionPreparaciones::class)->previsualizar($usuario, $this->producto->id, '10');
    $reales = array_map(fn (array $fila) => $fila['estandar'], $calculo['ingredientes']);
    $reales[$this->arroz->id] = '1900';
    expect(fn () => borradorProduccion($usuario, $this->producto, reales: $reales, motivo: 'Consumo medido'))->toThrow(AuthorizationException::class);
    $usuario->givePermissionTo('preparaciones.ajustar_consumos');
    expect(fn () => borradorProduccion($usuario, $this->producto, reales: $reales))->toThrow(ValidationException::class);
    $preparacion = borradorProduccion($usuario, $this->producto, reales: $reales, motivo: 'Consumo real medido');
    confirmarProduccion($usuario, $preparacion);
    expect($this->arroz->fresh()->stock_actual)->toBe('3100.0000')->and($preparacion->fresh()->motivo_ajuste)->toBe('Consumo real medido')
        ->and($this->producto->receta->detalles()->where('articulo_id', $this->arroz->id)->first()->cantidad)->toBe('180.0000');
});

test('extra ingredients missing lines and entirely zero consumption cannot manufacture stock', function () {
    $calculo = app(GestionPreparaciones::class)->previsualizar($this->administrador, $this->producto->id, '10');
    $reales = array_map(fn (array $fila) => $fila['estandar'], $calculo['ingredientes']);
    $extra = $reales + [$this->producto->articulo_id => '1'];
    expect(fn () => borradorProduccion($this->administrador, $this->producto, reales: $extra))->toThrow(ValidationException::class);
    expect(fn () => borradorProduccion($this->administrador, $this->producto, reales: [$this->arroz->id => '1800']))->toThrow(ValidationException::class);
    $ceros = array_fill_keys(array_keys($reales), '0');
    expect(fn () => borradorProduccion($this->administrador, $this->producto, reales: $ceros, motivo: 'Sin consumo'))->toThrow(ValidationException::class);
    expect(Preparacion::count())->toBe(0)->and(MovimientoInventario::count())->toBe(0);
});

test('saved draft preserves its recipe snapshot even after the standard recipe changes', function () {
    $preparacion = borradorProduccion($this->administrador, $this->producto);
    $this->producto->receta->update(['rendimiento' => '2']);
    confirmarProduccion($this->administrador, $preparacion);
    expect($this->arroz->fresh()->stock_actual)->toBe('3200.0000')
        ->and($preparacion->fresh()->rendimiento_receta)->toBe('1.0000')
        ->and($preparacion->detalles()->where('articulo_id', $this->arroz->id)->first()->cantidad_estandar)->toBe('1800.0000');
});

test('a changed recipe between calculation and save must be explicitly recalculated', function () {
    $form = Livewire::test(PreparacionFormulario::class)->set('productoId', (string) $this->producto->id)->set('cantidad', '10')->call('calcular');
    $this->producto->receta->update(['rendimiento' => '2']);
    $form->call('save')->assertHasErrors('seguridad')->call('calcular')->call('save')->assertHasNoErrors();
    expect(Preparacion::firstOrFail()->detalles()->where('articulo_id', $this->arroz->id)->first()->cantidad_estandar)->toBe('900.0000');
});

test('inactive recipe ingredients product or destination block confirmation', function (string $recurso) {
    $preparacion = borradorProduccion($this->administrador, $this->producto);
    match ($recurso) {
        'receta' => $this->producto->receta->update(['activo' => false]),
        'ingrediente' => $this->arroz->update(['activo' => false]),
        'producto' => $this->producto->update(['activo' => false]),
        'destino' => $this->producto->areaPreparacion->update(['activo' => false]),
    };
    expect(fn () => confirmarProduccion($this->administrador, $preparacion))->toThrow(ValidationException::class);
    expect(MovimientoInventario::count())->toBe(0);
})->with(['receta', 'ingrediente', 'producto', 'destino']);

test('history keeps names quantities and costs after catalog and cost updates', function () {
    $preparacion = borradorProduccion($this->administrador, $this->producto);
    confirmarProduccion($this->administrador, $preparacion);
    $this->producto->update(['nombre' => 'Producto renombrado']);
    $this->arroz->update(['nombre' => 'Ingrediente renombrado', 'costo_promedio' => '10']);
    $this->get(route('preparaciones.ver', $preparacion))->assertOk()->assertSee('Chaufa (ejemplo)')->assertSee('Arroz (ejemplo)')
        ->assertDontSee('Ingrediente renombrado');
    expect($preparacion->fresh()->costo_total)->toBe('39.800000')
        ->and($preparacion->detalles()->where('articulo_id', $this->arroz->id)->first()->costo_unitario)->toBe('0.006000');
});

test('draft cancellation is idempotent and confirmed preparations cannot be edited or cancelled', function () {
    $preparacion = borradorProduccion($this->administrador, $this->producto);
    $gestion = app(GestionPreparaciones::class);
    $version = GestionPreparaciones::huellaBorrador($preparacion);
    $gestion->anular($this->administrador, $preparacion->id, 'No se realizó la preparación', $version);
    $gestion->anular($this->administrador, $preparacion->id, 'Otro motivo', $version);
    expect($preparacion->fresh()->estado)->toBe(EstadoPreparacion::ANULADA)->and(MovimientoInventario::count())->toBe(0);
    $confirmada = borradorProduccion($this->administrador, $this->producto);
    confirmarProduccion($this->administrador, $confirmada);
    expect(fn () => $gestion->anular($this->administrador, $confirmada->id, 'No debe anularse', ''))->toThrow(ValidationException::class);
    Livewire::test(PreparacionFormulario::class, ['preparacion' => $confirmada->fresh()])->assertForbidden();
    expect(MovimientoInventario::count())->toBe(4);
});

test('draft creator access costs and locked identifiers remain protected', function () {
    $preparacion = borradorProduccion($this->administrador, $this->producto);
    confirmarProduccion($this->administrador, $preparacion);
    $lector = User::factory()->create();
    $lector->givePermissionTo('preparaciones.ver');
    $this->actingAs($lector);
    $response = $this->get(route('preparaciones.ver', $preparacion))->assertOk()->assertDontSee('Costo total de producción')->assertDontSee('39.800000');
    expect($response->getContent())->not->toContain('costo_unitario');
    expect(fn () => Livewire::test(DetalleLivewire::class, ['preparacion' => $preparacion])->set('preparacionId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
    $lector->givePermissionTo('preparaciones.crear');
    $otroBorrador = borradorProduccion($this->administrador, $this->producto);
    expect(fn () => app(GestionPreparaciones::class)->anular($lector, $otroBorrador->id, 'No autorizado', GestionPreparaciones::huellaBorrador($otroBorrador)))->toThrow(HttpException::class);
});

test('confirmation checks latest draft contents and permission again after opening its dialog', function () {
    $preparacion = borradorProduccion($this->administrador, $this->producto);
    $componente = Livewire::test(DetalleLivewire::class, ['preparacion' => $preparacion])->call('requestConfirm')->assertDispatched('app:confirm');
    $preparacion->update(['observaciones' => 'Cambio concurrente']);
    $componente->call('confirmProduction')->assertDispatched('app:alert', fn ($evento, $datos) => $datos['type'] === 'error');
    $componente->call('requestConfirm');
    $this->administrador->roles->first()->revokePermissionTo('preparaciones.confirmar');
    $this->administrador->unsetRelations();
    $componente->call('confirmProduction')->assertForbidden();
    expect(MovimientoInventario::count())->toBe(0);
});

test('scaling rounds consumption up at four decimals and quantity overflow cannot partially consume stock', function () {
    expect(CalculoProduccion::escalar('0.0001', '1.0000', '3.0000'))->toBe('0.0001');
    $this->producto->articulo->update(['stock_actual' => '999999999999.9999']);
    $preparacion = borradorProduccion($this->administrador, $this->producto, '1');
    expect(fn () => confirmarProduccion($this->administrador, $preparacion))->toThrow(ValidationException::class);
    expect(MovimientoInventario::count())->toBe(0)->and($this->arroz->fresh()->stock_actual)->toBe('5000.0000');
});

test('history filters reset pagination and only drafts expose editing and confirmation controls', function () {
    $preparacion = borradorProduccion($this->administrador, $this->producto);
    Livewire::test(Preparaciones::class)->set('stateFilter', 'confirmada')->assertSee('No se encontraron preparaciones')
        ->call('clearFilters')->assertSee($preparacion->codigo)->call('gotoPage', 2)->set('search', $preparacion->codigo)->assertSet('paginators.page', 1);
    $this->get(route('preparaciones.ver', $preparacion))->assertOk()->assertSee('Confirmar producción');
    confirmarProduccion($this->administrador, $preparacion);
    $this->get(route('preparaciones.ver', $preparacion))->assertOk()->assertDontSee('Editar borrador')->assertDontSee('Confirmar producción');
});

test('prepared ingredients consume their existing stock without consuming their raw ingredients again', function () {
    $intermedio = Producto::where('codigo', 'EJ-EXTRA')->firstOrFail();
    $intermedio->articulo->update(['stock_actual' => '20', 'costo_promedio' => '2.5']);
    $recetas = app(GestionRecetas::class);
    $recetas->guardar($this->administrador, $intermedio->id, '1', '', true,
        ['huevo' => ['articuloId' => (string) $this->huevo->id, 'cantidad' => '1']], '');
    $recetas->guardar($this->administrador, $this->producto->id, '1', '', true,
        ['intermedio' => ['articuloId' => (string) $intermedio->articulo_id, 'cantidad' => '1']], GestionRecetas::huella($this->producto->receta));
    $preparacion = borradorProduccion($this->administrador, $this->producto, '5');
    confirmarProduccion($this->administrador, $preparacion);
    expect($intermedio->articulo->fresh()->stock_actual)->toBe('15.0000')
        ->and($this->huevo->fresh()->stock_actual)->toBe('50.0000')->and($this->arroz->fresh()->stock_actual)->toBe('5000.0000')
        ->and($preparacion->fresh()->costo_total)->toBe('12.500000')->and(MovimientoInventario::count())->toBe(2);
});

test('editing an old copy of a draft cannot replace a more recent saved revision', function () {
    $preparacion = borradorProduccion($this->administrador, $this->producto);
    $primero = Livewire::test(PreparacionFormulario::class, ['preparacion' => $preparacion]);
    $segundo = Livewire::test(PreparacionFormulario::class, ['preparacion' => $preparacion]);
    $primero->set('observaciones', 'Primera revisión guardada')->call('save')->assertHasNoErrors();
    $segundo->set('observaciones', 'Revisión antigua')->call('save')->assertHasErrors('seguridad');
    expect($preparacion->fresh()->observaciones)->toBe('Primera revisión guardada')->and(MovimientoInventario::count())->toBe(0);
});

test('authorized zero consumption of one ingredient creates no zero inventory movement', function () {
    $calculo = app(GestionPreparaciones::class)->previsualizar($this->administrador, $this->producto->id, '10');
    $reales = array_map(fn (array $fila) => $fila['estandar'], $calculo['ingredientes']);
    $reales[$this->huevo->id] = '0';
    $preparacion = borradorProduccion($this->administrador, $this->producto, reales: $reales, motivo: 'No se utilizó huevo en este lote');
    confirmarProduccion($this->administrador, $preparacion);
    expect($this->huevo->fresh()->stock_actual)->toBe('50.0000')->and(MovimientoInventario::count())->toBe(3)
        ->and($preparacion->detalles()->where('articulo_id', $this->huevo->id)->first()->cantidad_utilizada)->toBe('0.0000');
});
