<?php

use App\Livewire\Clientes\ClienteFormulario;
use App\Livewire\Clientes\Clientes;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\TipoDocumentoIdentidad;
use App\Models\User;
use App\Services\Clientes\GestionClientes;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([RolesPermisosSeeder::class, CatalogosSeeder::class]);
    $this->actor = User::factory()->create();
    $this->actor->assignRole('administrador');
    $this->actingAs($this->actor);
});

function datosCliente(array $cambios = []): array
{
    return array_replace(['tipo' => 'persona', 'nombre' => 'Juan Pérez', 'tipoId' => '', 'numero' => '', 'direccion' => '', 'telefono' => '', 'correo' => '', 'activo' => true], $cambios);
}

function crearCliente(User $actor, array $cambios = [], ?string $clave = null): Cliente
{
    return app(GestionClientes::class)->guardar($actor, null, $clave ?? (string) Str::uuid(), datosCliente($cambios))->refresh();
}

function documentoCliente(string $codigo): string
{
    return (string) TipoDocumentoIdentidad::where('codigo', $codigo)->value('id');
}

test('customer pages and actions require their specific permissions', function () {
    $cliente = crearCliente($this->actor);
    auth()->logout();
    $this->get(route('clientes.index'))->assertRedirect(route('login'));
    $lector = User::factory()->create();
    $this->actingAs($lector)->get(route('clientes.index'))->assertForbidden();
    $lector->givePermissionTo('clientes.ver');
    $this->get(route('clientes.index'))->assertOk()->assertSee('Juan Pérez')->assertDontSee('Nuevo cliente')->assertDontSee('Editar cliente Juan Pérez');
    $this->get(route('clientes.crear'))->assertForbidden();
    $this->get(route('clientes.editar', $cliente))->assertForbidden();
    expect(fn () => crearCliente($lector))->toThrow(AuthorizationException::class);
    Livewire::test(Clientes::class)->call('requestStateChange', $cliente->id)->assertForbidden();
});

test('person may register without a document and repeated registration creates one customer', function () {
    $clave = (string) Str::uuid();
    $cliente = crearCliente($this->actor, ['nombre' => '  Juan   Pérez ', 'telefono' => ' 999 111 222 ', 'correo' => ' juan@example.com '], $clave);
    expect($cliente->nombre)->toBe('Juan Pérez')->and($cliente->telefono)->toBe('999 111 222')->and($cliente->correo)->toBe('juan@example.com')
        ->and($cliente->numero_documento)->toBeNull()->and($cliente->tipo_documento_identidad_id)->toBeNull()->and($cliente->creado_por)->toBe($this->actor->id);
    expect(crearCliente($this->actor, [], $clave)->id)->toBe($cliente->id)->and(Cliente::count())->toBe(1);
    $evento = Activity::where('event', 'cliente_creado')->firstOrFail();
    expect($evento->causer_id)->toBe($this->actor->id)->and($evento->properties['despues']['nombre'])->toBe('Juan Pérez');
    $otro = User::factory()->create();
    $otro->assignRole('cajero');
    expect(fn () => crearCliente($otro, [], $clave))->toThrow(HttpException::class);
});

test('company form sets RUC and requires name number and address', function () {
    Livewire::test(ClienteFormulario::class)->set('tipo', 'empresa')->assertSet('tipoId', documentoCliente('6'))->set('nombre', 'Restaurante del Norte')
        ->set('numero', '20123456789')->call('save')->assertHasErrors('direccion')->set('direccion', 'Av. Principal 123')->call('save')->assertHasNoErrors()->assertRedirect(route('clientes.index'));
    $cliente = Cliente::firstOrFail();
    expect($cliente->tipo->value)->toBe('empresa')->and($cliente->numero_documento)->toBe('20123456789');
    expect(fn () => crearCliente($this->actor, ['tipo' => 'empresa', 'tipoId' => documentoCliente('1'), 'numero' => '12345678', 'direccion' => 'Dirección']))->toThrow(ValidationException::class);
    expect(fn () => crearCliente($this->actor, ['tipo' => 'empresa', 'direccion' => 'Dirección']))->toThrow(ValidationException::class);
});

test('natural person with RUC remains a natural person and documents retain leading zeros', function () {
    $persona = crearCliente($this->actor, ['tipoId' => documentoCliente('6'), 'numero' => '10123456789']);
    $dni = crearCliente($this->actor, ['tipoId' => documentoCliente('1'), 'numero' => '00123456']);
    expect($persona->tipo->value)->toBe('persona')->and($persona->direccion)->toBeNull()->and($dni->numero_documento)->toBe('00123456');
    $extranjero = crearCliente($this->actor, ['tipoId' => documentoCliente('7'), 'numero' => ' ab-0123 ']);
    expect($extranjero->numero_documento)->toBe('AB-0123');
});

test('identity validation rejects incorrect DNI RUC partial pairs and unsupported characters', function (array $cambios) {
    expect(fn () => crearCliente($this->actor, $cambios))->toThrow(ValidationException::class);
    expect(Cliente::count())->toBe(0)->and(Activity::where('event', 'cliente_creado')->count())->toBe(0);
})->with([
    fn () => ['tipoId' => documentoCliente('1'), 'numero' => '1234567'],
    fn () => ['tipoId' => documentoCliente('1'), 'numero' => 'AB123456'],
    fn () => ['tipoId' => documentoCliente('6'), 'numero' => '2012345678'],
    fn () => ['tipoId' => documentoCliente('6'), 'numero' => '2012345678A'],
    fn () => ['tipoId' => documentoCliente('7'), 'numero' => '../private'],
    [['numero' => '12345678']],
    fn () => ['tipoId' => documentoCliente('1')],
]);

test('document duplicates are rejected even if inactive but document kinds remain distinct', function () {
    $cliente = crearCliente($this->actor, ['tipoId' => documentoCliente('1'), 'numero' => '12345678', 'activo' => false]);
    expect(fn () => crearCliente($this->actor, ['tipoId' => documentoCliente('1'), 'numero' => '12345678']))->toThrow(ValidationException::class);
    crearCliente($this->actor, ['tipoId' => documentoCliente('4'), 'numero' => '12345678']);
    Livewire::test(ClienteFormulario::class, ['cliente' => $cliente])->set('telefono', '999111222')->call('save')->assertHasNoErrors();
    expect(Cliente::count())->toBe(2)->and($cliente->fresh()->telefono)->toBe('999111222');
});

test('contact and name limits are validated and empty optional fields remain null', function () {
    Livewire::test(ClienteFormulario::class)->set('nombre', 'Cliente válido')->set('correo', 'no-es-correo')->call('save')->assertHasErrors('correo')
        ->set('correo', '')->set('telefono', str_repeat('9', 31))->call('save')->assertHasErrors('telefono')
        ->set('telefono', '')->set('nombre', str_repeat('x', 201))->call('save')->assertHasErrors('nombre');
    $cliente = crearCliente($this->actor);
    expect($cliente->telefono)->toBeNull()->and($cliente->correo)->toBeNull()->and($cliente->direccion)->toBeNull();
});

test('inactive document type cannot be assigned but existing identical identity can retain contact updates', function () {
    $cliente = crearCliente($this->actor, ['tipoId' => documentoCliente('1'), 'numero' => '12345678']);
    TipoDocumentoIdentidad::where('codigo', '1')->update(['activo' => false]);
    expect(fn () => crearCliente($this->actor, ['tipoId' => documentoCliente('1'), 'numero' => '87654321']))->toThrow(ValidationException::class);
    Livewire::test(ClienteFormulario::class, ['cliente' => $cliente])->set('telefono', '999111222')->call('save')->assertHasNoErrors();
    expect($cliente->fresh()->telefono)->toBe('999111222');
});

test('edits require editing permission without demanding create permission', function () {
    $cliente = crearCliente($this->actor);
    $editor = User::factory()->create();
    $editor->givePermissionTo(['clientes.ver', 'clientes.editar']);
    $this->actingAs($editor);
    $this->get(route('clientes.editar', $cliente))->assertOk();
    Livewire::test(ClienteFormulario::class, ['cliente' => $cliente])->set('nombre', 'Nombre corregido')->call('save')->assertHasNoErrors();
    $this->get(route('clientes.crear'))->assertForbidden();
    expect($cliente->fresh()->nombre)->toBe('Nombre corregido');
});

test('stale customer forms and state dialogs cannot overwrite later changes', function () {
    $cliente = crearCliente($this->actor);
    $viejo = Livewire::test(ClienteFormulario::class, ['cliente' => $cliente]);
    $estado = Livewire::test(Clientes::class)->call('requestStateChange', $cliente->id)->assertDispatched('app:confirm');
    Livewire::test(ClienteFormulario::class, ['cliente' => $cliente])->set('nombre', 'Nombre nuevo')->call('save')->assertHasNoErrors();
    $viejo->set('nombre', 'Nombre anterior')->call('save')->assertHasErrors('seguridad');
    $estado->call('confirmState')->assertDispatched('app:alert');
    expect($cliente->fresh()->nombre)->toBe('Nombre nuevo')->and($cliente->fresh()->activo)->toBeTrue();
});

test('deactivation requires confirmation preserves related orders and is idempotent', function () {
    $cliente = crearCliente($this->actor);
    $pedido = Pedido::create(['codigo' => 'CLI-PED', 'modalidad' => 'rapida', 'cliente_id' => $cliente->id, 'creado_por' => $this->actor->id, 'abierto_at' => now()]);
    $componente = Livewire::test(Clientes::class)->call('requestStateChange', $cliente->id)->assertDispatched('app:confirm');
    expect($cliente->fresh()->activo)->toBeTrue();
    $componente->call('confirmState')->assertHasNoErrors()->call('confirmState')->assertHasNoErrors();
    expect($cliente->fresh()->activo)->toBeFalse()->and($pedido->fresh()->cliente_id)->toBe($cliente->id)->and(Cliente::count())->toBe(1)
        ->and(Activity::where('event', 'cliente_estado')->count())->toBe(1);
    Livewire::test(Clientes::class)->call('requestStateChange', $cliente->id)->call('confirmState')->assertHasNoErrors();
    expect($cliente->fresh()->activo)->toBeTrue();
});

test('linked customers keep existing identity while contact fields remain editable and missing documents can be completed', function () {
    $cliente = crearCliente($this->actor, ['tipoId' => documentoCliente('1'), 'numero' => '12345678']);
    Pedido::create(['codigo' => 'CLI-LINK', 'modalidad' => 'rapida', 'cliente_id' => $cliente->id, 'creado_por' => $this->actor->id, 'abierto_at' => now()]);
    Livewire::test(ClienteFormulario::class, ['cliente' => $cliente])->set('numero', '87654321')->call('save')->assertHasErrors('seguridad');
    Livewire::test(ClienteFormulario::class, ['cliente' => $cliente])->set('tipo', 'empresa')->set('numero', '20123456789')->set('direccion', 'Av. Principal')->call('save')->assertHasErrors('seguridad');
    Livewire::test(ClienteFormulario::class, ['cliente' => $cliente])->set('telefono', '999123123')->call('save')->assertHasNoErrors();
    expect($cliente->fresh()->numero_documento)->toBe('12345678')->and($cliente->fresh()->telefono)->toBe('999123123');
    $pendiente = crearCliente($this->actor, ['nombre' => 'Cliente sin documento']);
    Pedido::create(['codigo' => 'CLI-PEND', 'modalidad' => 'rapida', 'cliente_id' => $pendiente->id, 'creado_por' => $this->actor->id, 'abierto_at' => now()]);
    Livewire::test(ClienteFormulario::class, ['cliente' => $pendiente])->set('tipoId', documentoCliente('1'))->set('numero', '87654321')->call('save')->assertHasNoErrors();
    expect($pendiente->fresh()->numero_documento)->toBe('87654321');
});

test('customer permissions are checked again after mount and after a confirmation dialog', function () {
    $cliente = crearCliente($this->actor);
    $formulario = Livewire::test(ClienteFormulario::class, ['cliente' => $cliente])->set('nombre', 'Cambio no permitido');
    $lista = Livewire::test(Clientes::class)->call('requestStateChange', $cliente->id);
    $this->actor->roles->first()->revokePermissionTo('clientes.editar');
    $this->actor->unsetRelations();
    $formulario->call('save')->assertForbidden();
    $lista->call('confirmState')->assertForbidden();
    expect($cliente->fresh()->nombre)->toBe('Juan Pérez')->and($cliente->fresh()->activo)->toBeTrue();
});

test('sensitive component identifiers and versions cannot be replaced', function () {
    $cliente = crearCliente($this->actor);
    foreach (['clienteId' => 99, 'version' => 'otra', 'clave' => (string) Str::uuid()] as $campo => $valor) {
        expect(fn () => Livewire::test(ClienteFormulario::class, ['cliente' => $cliente])->set($campo, $valor))->toThrow(CannotUpdateLockedPropertyException::class);
    }
    expect(fn () => Livewire::test(Clientes::class)->set('stateId', $cliente->id))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('search supports name document phone and email and filters reset pagination', function () {
    crearCliente($this->actor, ['nombre' => 'Ana López', 'telefono' => '999123456', 'correo' => 'ana@example.com', 'tipoId' => documentoCliente('1'), 'numero' => '12345678']);
    crearCliente($this->actor, ['tipo' => 'empresa', 'nombre' => 'Empresa Norte', 'tipoId' => documentoCliente('6'), 'numero' => '20123456789', 'direccion' => 'Av. Norte', 'activo' => false]);
    foreach (['Ana', '999123456', 'ana@example.com', '12345678'] as $buscar) {
        Livewire::test(Clientes::class)->set('search', $buscar)->assertSee('Ana López');
    }
    Livewire::test(Clientes::class)->set('typeFilter', 'empresa')->assertSee('Empresa Norte')->assertDontSee('Ana López')->set('stateFilter', 'activo')->assertSee('No hay clientes con estos filtros.');
    Livewire::test(Clientes::class)->call('gotoPage', 2)->set('search', 'Ana')->assertSet('paginators.page', 1)->assertSee('Ana López')->call('clearFilters')->assertSee('Empresa Norte');
    Livewire::test(Clientes::class)->set('stateFilter', 'no-valido')->set('typeFilter', 'otro')->assertSee('Ana López')->assertSee('Empresa Norte');
});

test('customer pagination renders multiple pages and stored HTML remains escaped', function () {
    for ($i = 0; $i < 16; $i++) {
        crearCliente($this->actor, ['nombre' => sprintf('Cliente %02d', $i)]);
    }
    Livewire::test(Clientes::class)->assertSee('Cliente 00')->assertDontSee('Cliente 15')->call('gotoPage', 2)->assertSee('Cliente 15');
    crearCliente($this->actor, ['nombre' => '<img src=x onerror=alert(1)>']);
    Livewire::test(Clientes::class)->set('search', '<img')->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)->assertDontSee('<img src=x onerror=alert(1)>', false);
});

test('legacy customers remain editable without rewriting their registration metadata', function () {
    $cliente = Cliente::create(['nombre' => 'Cliente previo', 'tipo' => 'persona'])->refresh();
    Livewire::test(ClienteFormulario::class, ['cliente' => $cliente])->set('telefono', '999222333')->call('save')->assertHasNoErrors();
    expect($cliente->fresh()->telefono)->toBe('999222333')->and($cliente->fresh()->clave_registro)->toBeNull()->and($cliente->fresh()->creado_por)->toBeNull();
});
