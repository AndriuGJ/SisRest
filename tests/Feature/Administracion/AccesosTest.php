<?php

use App\Livewire\Administracion\Roles;
use App\Livewire\Administracion\RolFormulario;
use App\Livewire\Administracion\Usuarios;
use App\Models\User;
use App\Services\Administracion\GestionAccesos;
use App\Support\Alertas;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RolesPermisosSeeder::class);
    $this->administrador = User::factory()->create([
        'name' => 'Administrador de prueba', 'username' => 'admin-prueba',
    ]);
    $this->administrador->assignRole('administrador');
});

test('administration pages require authentication and their respective permissions', function () {
    $this->get(route('usuarios.index'))->assertRedirect(route('login'));
    $this->get(route('roles.index'))->assertRedirect(route('login'));

    $mozo = User::factory()->create();
    $mozo->assignRole('mozo');
    $this->actingAs($mozo);
    $this->get(route('usuarios.index'))->assertForbidden();
    $this->get(route('roles.index'))->assertForbidden();
    $this->get(route('roles.crear'))->assertForbidden();
    $this->get(route('roles.editar', Role::findByName('administrador')))->assertForbidden();
    $this->get(route('dashboard'))->assertDontSee('href="'.route('usuarios.index').'"', false);
});

test('administrator can render the users roles and permission editor pages', function () {
    $this->actingAs($this->administrador);
    $this->get(route('usuarios.index'))->assertOk()->assertSee('Nuevo usuario');
    $this->get(route('roles.index'))->assertOk()->assertSee('Nuevo rol');
    $this->get(route('roles.crear'))->assertOk()->assertSee('Nombre del rol');
    $this->get(route('roles.editar', Role::findByName('mozo')))->assertOk()->assertSee('Confirmar entregas');
});

test('administrator creates an active user with one role normalized login and optional email', function () {
    $this->actingAs($this->administrador);
    $rol = Role::findByName('mozo');

    Livewire::test(Usuarios::class)->call('newUser')
        ->set('name', 'Ana Atención')->set('username', '  MOZO01  ')
        ->set('email', '')->set('roleId', (string) $rol->id)
        ->set('password', 'Una-clave-nueva-123')->set('password_confirmation', 'Una-clave-nueva-123')
        ->call('save')->assertHasNoErrors()->assertSet('showForm', false)->assertSet('password', '')
        ->assertDispatched('app:alert', text: 'Usuario registrado.', type: 'success', toast: true);

    $usuario = User::query()->where('username', 'mozo01')->firstOrFail();
    expect($usuario->email)->toBeNull()
        ->and($usuario->activo)->toBeTrue()
        ->and($usuario->hasRole('mozo'))->toBeTrue()
        ->and($usuario->roles()->count())->toBe(1)
        ->and(Hash::check('Una-clave-nueva-123', $usuario->password))->toBeTrue()
        ->and($usuario->can('cobros.crear'))->toBeFalse();
});

test('editing a user preserves the password and changes the assigned role', function () {
    $usuario = User::factory()->create(['username' => 'mozo01']);
    $usuario->assignRole('mozo');
    $hash = $usuario->password;
    $this->actingAs($this->administrador);

    Livewire::test(Usuarios::class)->call('editUser', $usuario->id)
        ->set('name', 'Carlos Caja')->set('email', 'CAJA@EXAMPLE.COM')
        ->set('roleId', (string) Role::findByName('cajero')->id)
        ->call('save')->assertHasNoErrors();

    $usuario->refresh();
    expect($usuario->name)->toBe('Carlos Caja')
        ->and($usuario->email)->toBe('caja@example.com')
        ->and($usuario->password)->toBe($hash)
        ->and($usuario->hasRole('cajero'))->toBeTrue()
        ->and($usuario->hasRole('mozo'))->toBeFalse();
});

test('user form validates normalized unique names passwords and guarded role selection', function () {
    User::factory()->create(['username' => 'mozo01']);
    $api = Role::create(['name' => 'externo', 'guard_name' => 'api']);
    $this->actingAs($this->administrador);

    Livewire::test(Usuarios::class)->call('newUser')
        ->set('name', 'Duplicado')->set('username', 'MOZO01')->set('roleId', (string) $api->id)
        ->set('password', 'corta')->set('password_confirmation', 'otra')
        ->call('save')->assertHasErrors(['username', 'password', 'roleId']);

    expect(User::count())->toBe(2);
});

test('user search combines name role and state filters with pagination', function () {
    $ana = User::factory()->create(['name' => 'Ana Atención', 'username' => 'ana01', 'activo' => true]);
    $ana->assignRole('mozo');
    $beatriz = User::factory()->create(['name' => 'Beatriz Cocina', 'username' => 'bet01']);
    $beatriz->assignRole('cocina');
    $carlos = User::factory()->create(['name' => 'Carlos Inactivo', 'username' => 'car01', 'activo' => false]);
    $carlos->assignRole('mozo');
    $this->actingAs($this->administrador);

    Livewire::test(Usuarios::class)
        ->set('roleFilter', (string) Role::findByName('mozo')->id)
        ->set('stateFilter', 'activo')
        ->assertSee('Ana Atención')->assertDontSee('Beatriz Cocina')->assertDontSee('Carlos Inactivo')
        ->set('search', 'sin-coincidencias')->assertSee('No se encontraron usuarios')
        ->call('clearFilters')->assertSee('Carlos Inactivo');
});

test('user activation changes access without deleting the account and revokes sessions', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('mozo');
    DB::table('sessions')->insert([
        'id' => 'sesion-mozo', 'user_id' => $usuario->id, 'payload' => 'payload',
        'last_activity' => time(),
    ]);
    $token = $usuario->remember_token;
    $this->actingAs($this->administrador);

    Livewire::test(Usuarios::class)->call('requestStateChange', $usuario->id)
        ->call('confirmState')->assertHasNoErrors();

    expect($usuario->fresh()->activo)->toBeFalse()
        ->and($usuario->fresh()->remember_token)->not->toBe($token)
        ->and(DB::table('sessions')->where('user_id', $usuario->id)->count())->toBe(0);

    Livewire::test(Usuarios::class)->call('requestStateChange', $usuario->id)
        ->call('confirmState')->assertHasNoErrors();
    expect($usuario->fresh()->activo)->toBeTrue();
});

test('searching from a later page returns to the first page of filtered users', function () {
    User::factory()->count(16)->create();
    User::factory()->create(['name' => 'Zeta Búsqueda', 'username' => 'zeta.busqueda']);
    $this->actingAs($this->administrador);

    Livewire::test(Usuarios::class)->call('gotoPage', 2)
        ->set('search', 'zeta.busqueda')
        ->assertSet('paginators.page', 1)
        ->assertSee('Zeta Búsqueda');
});

test('administrator cannot deactivate their own account or change their own role', function () {
    $this->actingAs($this->administrador);
    Livewire::test(Usuarios::class)->call('requestStateChange', $this->administrador->id)
        ->call('confirmState')->assertHasErrors('seguridad')
        ->assertDispatched('app:alert', fn ($event, $params) => $params['type'] === 'error' && $params['toast'] === false);
    Livewire::test(Usuarios::class)->call('editUser', $this->administrador->id)
        ->set('roleId', (string) Role::findByName('mozo')->id)
        ->call('save')->assertHasErrors('roleId');

    expect($this->administrador->fresh()->activo)->toBeTrue()
        ->and($this->administrador->fresh()->hasRole('administrador'))->toBeTrue();
});

test('password reset changes the hash revokes sessions and does not log the password', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('cajero');
    DB::table('sessions')->insert([
        'id' => 'sesion-caja', 'user_id' => $usuario->id, 'payload' => 'payload',
        'last_activity' => time(),
    ]);
    $this->actingAs($this->administrador);
    Livewire::test(Usuarios::class)->call('requestPasswordReset', $usuario->id)
        ->set('newPassword', 'Una-clave-restablecida-123')
        ->set('newPassword_confirmation', 'Una-clave-restablecida-123')
        ->call('resetPassword')->assertHasNoErrors()->assertSet('newPassword', '');

    expect(Hash::check('Una-clave-restablecida-123', $usuario->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $usuario->id)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'password_restablecido')->count())->toBe(1)
        ->and(Activity::all()->toJson())->not->toContain('Una-clave-restablecida-123');
});

test('resetting the current administrator password returns them to login', function () {
    $this->actingAs($this->administrador);
    Livewire::test(Usuarios::class)->call('requestPasswordReset', $this->administrador->id)
        ->set('newPassword', 'Otra-clave-propia-123')->set('newPassword_confirmation', 'Otra-clave-propia-123')
        ->call('resetPassword')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('locked identifiers cannot be replaced by a client payload', function () {
    $this->actingAs($this->administrador);
    expect(fn () => Livewire::test(Usuarios::class)->set('userId', 9000))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('revoking access also blocks a livewire action after the page was opened', function () {
    $this->actingAs($this->administrador);
    $componente = Livewire::test(Usuarios::class);
    Role::findByName('administrador')->revokePermissionTo('usuarios.gestionar');
    $this->administrador->unsetRelations();
    $componente->call('newUser')->assertForbidden();
    expect(User::count())->toBe(1);
});

test('roles can be created and updated using permission groups', function () {
    $this->actingAs($this->administrador);
    Livewire::test(RolFormulario::class)
        ->set('name', 'supervisor')->call('toggleGroup', 'clientes')
        ->call('save')->assertHasNoErrors()->assertRedirect(route('roles.index'));
    $rol = Role::findByName('supervisor');
    expect($rol->hasPermissionTo('clientes.crear'))->toBeTrue()
        ->and($rol->hasPermissionTo('usuarios.gestionar'))->toBeFalse();

    Livewire::test(RolFormulario::class, ['rol' => $rol])
        ->set('permissions', ['clientes.ver'])->call('save')->assertHasNoErrors();
    expect($rol->fresh()->permissions()->count())->toBe(1);
    Livewire::test(Roles::class)->set('search', 'supervisor')->assertSee('Supervisor');
});

test('system roles keep their names and invalid permissions are rejected', function () {
    $this->actingAs($this->administrador);
    Livewire::test(RolFormulario::class, ['rol' => Role::findByName('mozo')])
        ->set('name', 'otro-nombre')->call('save')->assertHasErrors('name');
    Livewire::test(RolFormulario::class)->set('name', 'supervisor')
        ->set('permissions', ['permiso.inventado'])->call('save')->assertHasErrors('permissions.0');
    Permission::create(['name' => 'solo.api', 'guard_name' => 'api']);
    Livewire::test(RolFormulario::class)->set('name', 'supervisor')
        ->set('permissions', ['solo.api'])->call('save')->assertHasErrors('permissions.0');
});

test('removing the final management permission is rolled back without leaving stale access cache', function () {
    $this->actingAs($this->administrador);
    $rol = Role::findByName('administrador');
    $permisos = $rol->permissions->pluck('name')->reject(fn ($name) => $name === 'roles.gestionar')->values()->all();
    Livewire::test(RolFormulario::class, ['rol' => $rol])
        ->set('permissions', $permisos)->call('save')->assertHasErrors('seguridad');

    expect($this->administrador->fresh()->can('roles.gestionar'))->toBeTrue()
        ->and($this->administrador->fresh()->can('usuarios.gestionar'))->toBeTrue();
});

test('delegated user administration cannot grant or modify privileges above its own access', function () {
    $delegado = User::factory()->create();
    $delegado->givePermissionTo('usuarios.gestionar');
    $this->actingAs($delegado);
    $gestion = app(GestionAccesos::class);
    expect(fn () => $gestion->cambiarEstado($delegado, $this->administrador->id, false))
        ->toThrow(HttpException::class);
    expect(fn () => $gestion->restablecerPassword($delegado, $this->administrador->id, 'Una-clave-nueva-123', 'Una-clave-nueva-123'))
        ->toThrow(HttpException::class);

    Livewire::test(Usuarios::class)->call('newUser')
        ->set('name', 'Escalada')->set('username', 'escalada')
        ->set('roleId', (string) Role::findByName('administrador')->id)
        ->set('password', 'Una-clave-nueva-123')->set('password_confirmation', 'Una-clave-nueva-123')
        ->call('save')->assertForbidden();
    expect(User::query()->where('username', 'escalada')->exists())->toBeFalse();
});

test('delegated role administration cannot add capabilities it does not possess', function () {
    $delegado = User::factory()->create();
    $delegado->givePermissionTo('roles.gestionar');
    $this->actingAs($delegado);
    Livewire::test(RolFormulario::class)->set('name', 'escalada')
        ->set('permissions', ['usuarios.gestionar'])->call('save')->assertForbidden();
    expect(Role::query()->where('name', 'escalada')->exists())->toBeFalse();
});

test('complete administrator can restore a noncritical permission removed from their own role', function () {
    $rol = Role::findByName('administrador');
    $rol->revokePermissionTo('pos.ver');
    $this->administrador->unsetRelations();
    $this->actingAs($this->administrador);
    expect($this->administrador->can('pos.ver'))->toBeFalse();

    Livewire::test(RolFormulario::class, ['rol' => $rol])
        ->call('selectAll')->call('save')->assertHasNoErrors();

    expect($this->administrador->fresh()->can('pos.ver'))->toBeTrue();
});

test('requesting confirmation does not mutate users until the confirmed action runs', function () {
    $usuario = User::factory()->create(['name' => '<b>Nombre literal</b>']);
    $usuario->assignRole('mozo');
    $this->actingAs($this->administrador);

    $componente = Livewire::test(Usuarios::class)->call('requestStateChange', $usuario->id)
        ->assertDispatched('app:confirm', fn ($event, $params) => $params['title'] === 'Desactivar usuario'
            && $params['action'] === 'confirmState'
            && is_string($params['componentId'])
            && str_contains($params['text'], '<b>Nombre literal</b>')
            && $params['danger'] === true)
        ->assertNotDispatched('app:alert');

    expect($usuario->fresh()->activo)->toBeTrue();
    $componente->call('confirmState')->assertHasNoErrors()->assertSet('stateUserId', null)
        ->assertDispatched('app:alert', text: 'Usuario desactivado.', type: 'success', toast: true);
    expect($usuario->fresh()->activo)->toBeFalse();
    $componente->call('confirmState')->assertStatus(404);
});

test('redirect alerts are escaped in the layout and consumed once', function () {
    $this->actingAs($this->administrador);
    Alertas::flash('<img src=x onerror=alert(1)>', 'warning', toast: false);

    $this->get(route('dashboard'))->assertOk()->assertSee('data-app-alert', false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('data-app-alert', false);
});
