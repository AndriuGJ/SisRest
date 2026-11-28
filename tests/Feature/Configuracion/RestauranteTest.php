<?php

use App\Enums\EstadoPedido;
use App\Livewire\Configuracion\Mesas;
use App\Livewire\Configuracion\Salones;
use App\Models\Mesa;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\Salon;
use App\Models\User;
use App\Services\Configuracion\GestionRestaurante;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\ConfiguracionInicialSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesPermisosSeeder::class);
    $this->administrador = User::factory()->create();
    $this->administrador->assignRole('administrador');
    $this->salon = Salon::create(['codigo' => 'principal', 'nombre' => 'Principal']);
    $this->mesa = Mesa::create(['salon_id' => $this->salon->id, 'codigo' => '01', 'capacidad' => 4]);
    $this->actingAs($this->administrador);
});

function pedidoDePrueba(Mesa $mesa, User $actor, EstadoPedido $estado = EstadoPedido::ABIERTO): Pedido
{
    return Pedido::create([
        'codigo' => 'PED-'.$mesa->id.'-'.$estado->value, 'modalidad' => 'mesa',
        'mesa_id' => $mesa->id, 'mozo_id' => $actor->id, 'creado_por' => $actor->id,
        'estado' => $estado, 'abierto_at' => now(),
    ]);
}

test('restaurant configuration requires authentication and its specific permission', function () {
    auth()->logout();
    $this->get(route('configuracion.salones'))->assertRedirect(route('login'));
    $this->get(route('configuracion.mesas'))->assertRedirect(route('login'));
    $mozo = User::factory()->create();
    $mozo->assignRole('mozo');
    $this->actingAs($mozo);
    $this->get(route('configuracion.salones'))->assertForbidden();
    $this->get(route('configuracion.mesas'))->assertForbidden();
    $this->get(route('dashboard'))->assertDontSee('href="'.route('configuracion.salones').'"', false);
    expect(fn () => app(GestionRestaurante::class)->cambiarEstadoMesa($mozo, $this->mesa->id, false))->toThrow(AuthorizationException::class);
    $mozo->givePermissionTo('configuracion.restaurante');
    $this->get(route('configuracion.salones'))->assertOk();
    $this->get(route('configuracion.mesas'))->assertOk();
});


test('users and roles navigation links are available on their list pages', function () {
    foreach (['usuarios.index', 'roles.index'] as $pagina) {

        $html = $this->get(route($pagina))
            ->assertOk()
            ->getContent();

        expect(substr_count(
            $html,
            'href="'.route('usuarios.index').'"'
        ))->toBeGreaterThanOrEqual(1);

        expect(substr_count(
            $html,
            'href="'.route('roles.index').'"'
        ))->toBeGreaterThanOrEqual(1);
    }
});


test('salons can be created edited ordered and audited with normalized unique codes', function () {
    Livewire::test(Salones::class)->call('newRecord')
        ->set('nombre', '  Terraza   exterior  ')->set('codigo', ' TERRAZA ')->set('orden', '2')
        ->call('save')->assertHasNoErrors()->assertSet('showForm', false)
        ->assertDispatched('app:alert', text: 'Salón registrado.', type: 'success', toast: true);
    $salon = Salon::where('codigo', 'terraza')->firstOrFail();
    expect($salon->nombre)->toBe('Terraza exterior');
    Livewire::test(Salones::class)->call('editRecord', $salon->id)->set('nombre', 'Terraza nueva')->set('orden', '0')
        ->call('save')->assertHasNoErrors();
    Livewire::test(Salones::class)->set('nombre', 'Duplicado')->set('codigo', 'Terraza')->call('save')->assertHasErrors('codigo');
    Livewire::test(Salones::class)->set('nombre', 'Nuevo')->set('codigo', 'código con espacios')->set('orden', '-1')
        ->call('save')->assertHasErrors(['codigo', 'orden']);
    expect($salon->fresh()->nombre)->toBe('Terraza nueva')
        ->and(Activity::where('subject_type', Salon::class)->where('subject_id', $salon->id)->count())->toBe(2);
    Livewire::test(Salones::class)->assertSeeInOrder(['Principal', 'Terraza nueva']);
});

test('mesas use unique names per salon and validate salon and optional positive capacity', function () {
    $terraza = Salon::create(['codigo' => 'terraza', 'nombre' => 'Terraza']);
    Livewire::test(Mesas::class)->set('salonFilter', (string) $terraza->id)->call('newRecord')
        ->assertSet('salonId', (string) $terraza->id)->set('codigo', '  vip   1  ')->set('capacidad', '')
        ->call('save')->assertHasNoErrors()->assertDispatched('app:alert', text: 'Mesa registrada.', type: 'success', toast: true);
    $mesa = Mesa::where('salon_id', $terraza->id)->firstOrFail();
    expect($mesa->codigo)->toBe('VIP 1')->and($mesa->capacidad)->toBeNull();
    Livewire::test(Mesas::class)->call('editRecord', $mesa->id)->set('codigo', '01')->set('capacidad', '6')
        ->call('save')->assertHasNoErrors();
    expect($mesa->fresh()->capacidad)->toBe(6);
    Livewire::test(Mesas::class)->set('salonId', (string) $terraza->id)->set('codigo', '01')->call('save')->assertHasErrors('codigo');
    Livewire::test(Mesas::class)->set('salonId', '999999')->set('codigo', 'otra')->set('capacidad', '0')
        ->call('save')->assertHasErrors(['salonId', 'capacidad']);
    Livewire::test(Mesas::class)->set('salonId', (string) $this->salon->id)->set('codigo', 'otra')->set('capacidad', '65536')
        ->call('save')->assertHasErrors('capacidad');
});

test('salon deactivation preserves individual table settings and blocks table activation', function () {
    $inactiva = Mesa::create(['salon_id' => $this->salon->id, 'codigo' => '02', 'activo' => false]);
    $componente = Livewire::test(Salones::class)->call('requestStateChange', $this->salon->id)->assertDispatched('app:confirm');
    expect($this->salon->fresh()->activo)->toBeTrue();
    $componente->call('confirmState')->assertHasNoErrors()->assertSet('stateId', null);
    expect($this->salon->fresh()->activo)->toBeFalse()->and($this->mesa->fresh()->activo)->toBeTrue()->and($inactiva->fresh()->activo)->toBeFalse();
    Livewire::test(Mesas::class)->call('requestStateChange', $inactiva->id)->call('confirmState')
        ->assertHasErrors('seguridad')->assertDispatched('app:alert', fn ($event, $params) => $params['type'] === 'error' && ! $params['toast']);
    Livewire::test(Mesas::class)->set('salonId', (string) $this->salon->id)->set('codigo', '03')->call('save')->assertHasErrors('salonId');
    Livewire::test(Salones::class)->call('requestStateChange', $this->salon->id)->call('confirmState')->assertHasNoErrors();
    expect($this->mesa->fresh()->activo)->toBeTrue()->and($inactiva->fresh()->activo)->toBeFalse();
});

test('free tables can move salons and deactivate without deleting their order history', function () {
    $pedido = pedidoDePrueba($this->mesa, $this->administrador, EstadoPedido::CERRADO);
    $otro = Salon::create(['codigo' => 'otro', 'nombre' => 'Otro']);
    Livewire::test(Mesas::class)->call('editRecord', $this->mesa->id)->set('salonId', (string) $otro->id)
        ->set('capacidad', '8')->call('save')->assertHasNoErrors();
    $componente = Livewire::test(Mesas::class)->call('requestStateChange', $this->mesa->id)->assertDispatched('app:confirm');
    expect($this->mesa->fresh()->activo)->toBeTrue();
    $componente->call('confirmState')->assertHasNoErrors()->assertSet('stateId', null);
    expect($this->mesa->fresh()->activo)->toBeFalse()->and($this->mesa->fresh()->salon_id)->toBe($otro->id)
        ->and($pedido->fresh()->mesa_id)->toBe($this->mesa->id);
});

test('active orders block table changes and salon deactivation through every save path', function (EstadoPedido $estado) {
    $pedido = pedidoDePrueba($this->mesa, $this->administrador, $estado);
    if ($estado === EstadoPedido::CERRADO) {
        OcupacionMesa::create(['mesa_id' => $this->mesa->id, 'pedido_id' => $pedido->id]);
    }
    Livewire::test(Mesas::class)->call('requestStateChange', $this->mesa->id)->call('confirmState')->assertHasErrors('seguridad');
    Livewire::test(Mesas::class)->call('editRecord', $this->mesa->id)->set('codigo', 'CAMBIO')->call('save')->assertHasErrors('seguridad');
    Livewire::test(Salones::class)->call('requestStateChange', $this->salon->id)->call('confirmState')->assertHasErrors('seguridad');
    Livewire::test(Salones::class)->call('editRecord', $this->salon->id)->set('activo', false)->call('save')->assertHasErrors('seguridad');
    expect($this->salon->fresh()->activo)->toBeTrue()->and($this->mesa->fresh()->codigo)->toBe('01')->and($this->mesa->fresh()->activo)->toBeTrue();
    Livewire::test(Mesas::class)->assertSee('En uso');
})->with([EstadoPedido::ABIERTO, EstadoPedido::EN_PREPARACION, EstadoPedido::LISTO, EstadoPedido::ENTREGADO, EstadoPedido::CERRADO]);

test('config changes recheck occupation if an order opens after the edit form', function () {
    $componente = Livewire::test(Mesas::class)->call('editRecord', $this->mesa->id)->set('capacidad', '9');
    pedidoDePrueba($this->mesa, $this->administrador);
    $componente->call('save')->assertHasErrors('seguridad');
    expect($this->mesa->fresh()->capacidad)->toBe(4);
});

test('search and filters return to page one and find tables by salon and active state', function () {
    $otro = Salon::create(['codigo' => 'otro', 'nombre' => 'Otro']);
    Mesa::create(['salon_id' => $otro->id, 'codigo' => 'OTRA', 'activo' => false]);
    Livewire::test(Mesas::class)->set('salonFilter', (string) $otro->id)->set('stateFilter', 'inactivo')
        ->assertSee('OTRA')->assertDontSee('4 personas')->call('clearFilters')->assertSee('4 personas');
    for ($i = 1; $i <= 16; $i++) {
        Salon::create(['codigo' => 'salon'.$i, 'nombre' => 'Salón '.$i, 'orden' => $i]);
    }
    Livewire::test(Salones::class)->call('gotoPage', 2)->set('search', 'principal')->assertSet('paginators.page', 1)->assertSee('Principal');
    Livewire::test(Mesas::class)->call('gotoPage', 2)->set('search', '01')->assertSet('paginators.page', 1)->assertSee('4 personas');
});

test('restaurant component identifiers cannot be overwritten and revoked access blocks later actions', function () {
    expect(fn () => Livewire::test(Salones::class)->set('salonId', 9000))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(Mesas::class)->set('mesaId', 9000))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(Mesas::class)->set('stateId', 9000))->toThrow(CannotUpdateLockedPropertyException::class);
    $componente = Livewire::test(Salones::class)->call('editRecord', $this->salon->id)->set('nombre', 'No guardar');
    $this->administrador->roles->first()->revokePermissionTo('configuracion.restaurante');
    $this->administrador->unsetRelations();
    $componente->call('save')->assertForbidden();
    expect($this->salon->fresh()->nombre)->toBe('Principal');
});

test('initial seeder does not recreate a renamed default salon', function () {
    $this->salon->update(['codigo' => 'interior', 'nombre' => 'Interior']);
    $this->seed(CatalogosSeeder::class);
    $this->seed(ConfiguracionInicialSeeder::class);
    expect(Salon::count())->toBe(1)->and(Salon::first()->codigo)->toBe('interior');
});
