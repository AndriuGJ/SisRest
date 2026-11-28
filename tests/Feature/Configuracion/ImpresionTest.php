<?php

use App\Enums\EstadoTrabajoImpresion;
use App\Exceptions\FalloImpresion;
use App\Livewire\Configuracion\Destinos;
use App\Livewire\Configuracion\Impresoras;
use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\EnvioCocina;
use App\Models\EnvioCocinaDetalle;
use App\Models\Impresora;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\PruebaImpresion;
use App\Models\TrabajoImpresion;
use App\Models\User;
use App\Services\Configuracion\GestionDestinos;
use App\Services\Configuracion\GestionImpresoras;
use App\Services\Impresion\ProbarImpresora;
use App\Services\Impresion\TicketPrueba;
use App\Services\Impresion\TransporteEscPos;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\ConfiguracionInicialSeeder;
use Database\Seeders\EjemploInventarioSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([RolesPermisosSeeder::class, CatalogosSeeder::class, ConfiguracionInicialSeeder::class]);
    $this->administrador = User::factory()->create();
    $this->administrador->assignRole('administrador');
    $this->actingAs($this->administrador);
    $this->cocina = AreaPreparacion::where('codigo', 'cocina')->firstOrFail();
    $this->impresora = Impresora::where('codigo', 'cocina')->firstOrFail();
    $this->impresora->update(['host' => '192.168.1.80', 'activo' => true]);
});

test('destinations and printers use independent permissions and sidebar entries', function () {
    auth()->logout();
    $this->get(route('configuracion.destinos'))->assertRedirect(route('login'));
    $this->get(route('configuracion.impresoras'))->assertRedirect(route('login'));
    $usuario = User::factory()->create();
    $this->actingAs($usuario);
    $this->get(route('configuracion.destinos'))->assertForbidden();
    $this->get(route('configuracion.impresoras'))->assertForbidden();
    expect(fn () => app(GestionImpresoras::class)->cambiarEstado($usuario, $this->impresora->id, false))->toThrow(AuthorizationException::class);
    $usuario->givePermissionTo('impresoras.gestionar');
    $this->get(route('configuracion.impresoras'))->assertOk()->assertSee('Nueva impresora');
    $this->get(route('configuracion.destinos'))->assertForbidden();
    $this->get(route('dashboard'))->assertSee('href="'.route('configuracion.impresoras').'"', false)
        ->assertDontSee('href="'.route('configuracion.destinos').'"', false);
    $usuario->revokePermissionTo('impresoras.gestionar');
    $usuario->givePermissionTo('configuracion.restaurante');
    $this->get(route('configuracion.destinos'))->assertOk()->assertSee('Nuevo destino');
    $this->get(route('configuracion.impresoras'))->assertForbidden();
});

test('destination codes normalize and remain stable while names and activation can change', function () {
    Livewire::test(Destinos::class)->call('newRecord')->set('codigo', ' PARRILLA ')->set('nombre', '  Parrilla  exterior  ')
        ->call('save')->assertHasNoErrors()->assertDispatched('app:alert', text: 'Destino registrado.', type: 'success', toast: true);
    $destino = AreaPreparacion::where('codigo', 'parrilla')->firstOrFail();
    expect($destino->nombre)->toBe('Parrilla exterior');
    Livewire::test(Destinos::class)->call('editRecord', $destino->id)->set('nombre', 'Parrilla nueva')->call('save')->assertHasNoErrors();
    Livewire::test(Destinos::class)->call('editRecord', $destino->id)->set('codigo', 'otro')->call('save')->assertHasErrors('codigo');
    Livewire::test(Destinos::class)->set('codigo', 'Parrilla')->set('nombre', 'Duplicado')->call('save')->assertHasErrors('codigo');
    $componente = Livewire::test(Destinos::class)->call('requestStateChange', $destino->id)->assertDispatched('app:confirm');
    expect($destino->fresh()->activo)->toBeTrue();
    $componente->call('confirmState')->assertHasNoErrors();
    expect($destino->fresh()->activo)->toBeFalse();
    $this->seed(ConfiguracionInicialSeeder::class);
    expect(AreaPreparacion::where('codigo', 'parrilla')->count())->toBe(1)
        ->and(Activity::where('event', 'destino_configurado')->count())->toBe(3);
});

test('active products and pending deliveries block deactivation through both entry points', function () {
    $this->seed(EjemploInventarioSeeder::class);
    $producto = Producto::where('codigo', 'EJ-CHAUFA')->firstOrFail();
    Producto::where('area_preparacion_id', $this->cocina->id)->whereKeyNot($producto->id)->update(['activo' => false]);
    Livewire::test(Destinos::class)->call('editRecord', $this->cocina->id)->set('activo', false)->call('save')->assertHasErrors('seguridad');
    $producto->update(['activo' => false]);
    $pedido = Pedido::create(['codigo' => 'PED-PRUEBA', 'modalidad' => 'rapida', 'creado_por' => $this->administrador->id, 'abierto_at' => now()]);
    $detalle = PedidoDetalle::create(['pedido_id' => $pedido->id, 'producto_id' => $producto->id, 'descripcion' => 'Plato prueba', 'cantidad' => 2]);
    $envio = EnvioCocina::create(['pedido_id' => $pedido->id, 'area_preparacion_id' => $this->cocina->id, 'usuario_id' => $this->administrador->id, 'numero_envio' => 1, 'enviado_at' => now()]);
    $linea = EnvioCocinaDetalle::create(['envio_cocina_id' => $envio->id, 'pedido_detalle_id' => $detalle->id, 'descripcion' => 'Plato prueba', 'cantidad' => 2, 'cantidad_iniciada' => 2, 'cantidad_lista' => 2, 'cantidad_entregada' => 1]);
    Livewire::test(Destinos::class)->call('requestStateChange', $this->cocina->id)->call('confirmState')->assertHasErrors('seguridad');
    $linea->update(['cantidad_entregada' => 2]);
    Livewire::test(Destinos::class)->call('requestStateChange', $this->cocina->id)->call('confirmState')->assertHasNoErrors();
    expect($this->impresora->fresh()->activo)->toBeTrue()->and($this->cocina->fresh()->activo)->toBeFalse();
});

test('printer settings validate IP port paper width assignments and immutable code', function () {
    Livewire::test(Impresoras::class)->call('newRecord')->set('nombre', '  Impresora extra  ')->set('codigo', ' EXTRA ')
        ->call('save')->assertHasNoErrors();
    $extra = Impresora::where('codigo', 'extra')->firstOrFail();
    expect($extra->activo)->toBeFalse()->and($extra->host)->toBeNull();
    Livewire::test(Impresoras::class)->call('editRecord', $extra->id)->set('host', '192.168.1.81')->set('activo', true)
        ->set('ancho', '58')->call('save')->assertHasNoErrors();
    expect($extra->fresh()->ancho_mm)->toBe(58);
    Livewire::test(Impresoras::class)->call('editRecord', $extra->id)->set('codigo', 'cambiado')->call('save')->assertHasErrors('codigo');
    Livewire::test(Impresoras::class)->set('codigo', 'otro')->set('nombre', 'Otra')->set('host', 'http://192.168.1.5')
        ->set('puerto', '0')->set('ancho', '100')->set('uso', 'caja')->call('save')->assertHasErrors(['host', 'puerto', 'ancho', 'cajaId']);
    Livewire::test(Impresoras::class)->set('codigo', 'otro')->set('nombre', 'Otra')->set('activo', true)->call('save')->assertHasErrors('host');
});

test('printer reassignment replaces the selected box without sharing one printer across boxes', function () {
    $caja1 = Caja::where('codigo', 'caja_1')->firstOrFail();
    $caja2 = Caja::where('codigo', 'caja_2')->firstOrFail();
    Livewire::test(Impresoras::class)->call('editRecord', $this->impresora->id)->set('uso', 'caja')->set('cajaId', (string) $caja1->id)
        ->call('save')->assertHasNoErrors();
    expect($this->impresora->fresh()->area_preparacion_id)->toBeNull()->and($caja1->fresh()->impresora_id)->toBe($this->impresora->id);
    Livewire::test(Impresoras::class)->call('editRecord', $this->impresora->id)->set('cajaId', (string) $caja2->id)->call('save')->assertHasNoErrors();
    expect($caja1->fresh()->impresora_id)->toBeNull()->and($caja2->fresh()->impresora_id)->toBe($this->impresora->id);
    Livewire::test(Impresoras::class)->call('editRecord', $this->impresora->id)->set('uso', 'preparacion')->set('destinoId', (string) $this->cocina->id)->call('save')->assertHasNoErrors();
    expect($caja2->fresh()->impresora_id)->toBeNull()->and($this->impresora->fresh()->area_preparacion_id)->toBe($this->cocina->id);
    expect(Activity::where('event', 'impresora_configurada')->count())->toBe(3);
});

test('activation blocks duplicate network endpoints duplicate destinations and inactive destinations', function () {
    Livewire::test(Impresoras::class)->set('codigo', 'duplicada')->set('nombre', 'Duplicada')->set('host', '192.168.1.80')->set('activo', true)
        ->call('save')->assertHasErrors('host');
    Livewire::test(Impresoras::class)->set('codigo', 'duplicada')->set('nombre', 'Duplicada')->set('host', '192.168.1.81')->set('activo', true)
        ->set('uso', 'preparacion')->set('destinoId', (string) $this->cocina->id)->call('save')->assertHasErrors('destinoId');
    $barra = AreaPreparacion::where('codigo', 'barra')->firstOrFail();
    Livewire::test(Impresoras::class)->set('codigo', 'duplicada')->set('nombre', 'Duplicada')->set('host', '192.168.1.81')->set('activo', true)
        ->set('uso', 'preparacion')->set('destinoId', (string) $barra->id)->call('save')->assertHasErrors('destinoId');
    $caja = Impresora::where('codigo', 'caja_1')->firstOrFail();
    Livewire::test(Impresoras::class)->call('requestStateChange', $caja->id)->call('confirmState')->assertHasErrors('host');
});

test('a test sends once outside its database transaction and a duplicate token never reprints', function () {
    $base = DB::transactionLevel();
    $this->mock(TransporteEscPos::class)->shouldReceive('enviar')->once()->withArgs(function ($host, $port, $bytes) use ($base) {
        expect(DB::transactionLevel())->toBe($base)->and(PruebaImpresion::count())->toBe(1)
            ->and($host)->toBe('192.168.1.80')->and($port)->toBe(9100)
            ->and($bytes)->toContain('SIN VALOR COMERCIAL')->and($bytes)->toStartWith("\x1b@")->and($bytes)->toContain("\x1dV");

        return true;
    });
    $servicio = app(ProbarImpresora::class);
    $clave = (string) Str::uuid();
    $huella = ProbarImpresora::huella($this->impresora->fresh());
    $primera = $servicio->ejecutar($this->administrador, $this->impresora->id, $clave, $huella);
    $segunda = $servicio->ejecutar($this->administrador, $this->impresora->id, $clave, $huella);
    expect($primera->estado)->toBe(EstadoTrabajoImpresion::ENVIADO)->and($segunda->id)->toBe($primera->id)
        ->and(PruebaImpresion::count())->toBe(1)->and(Activity::where('event', 'prueba_impresion')->count())->toBe(1)
        ->and(DB::table('pedidos')->count())->toBe(0)->and(DB::table('trabajos_impresion')->count())->toBe(0);
});

test('connection failures and uncertain writes remain distinct and do not retry', function (bool $incierto, string $estado) {
    $this->mock(TransporteEscPos::class)->shouldReceive('enviar')->once()->andThrow(new FalloImpresion('Resultado de prueba', $incierto));
    $servicio = app(ProbarImpresora::class);
    $clave = (string) Str::uuid();
    $huella = ProbarImpresora::huella($this->impresora->fresh());
    expect($servicio->ejecutar($this->administrador, $this->impresora->id, $clave, $huella)->estado->value)->toBe($estado);
    $servicio->ejecutar($this->administrador, $this->impresora->id, $clave, $huella);
})->with([[false, 'error'], [true, 'resultado_incierto']]);

test('test confirmation does not print until accepted and protects its token', function () {
    $this->mock(TransporteEscPos::class)->shouldReceive('enviar')->once();
    $componente = Livewire::test(Impresoras::class)->call('requestTest', $this->impresora->id)->assertDispatched('app:confirm');
    expect(PruebaImpresion::count())->toBe(0);
    $componente->call('confirmTest')->assertHasNoErrors()->assertSet('testToken', null)
        ->assertDispatched('app:alert', fn ($event, $params) => $params['type'] === 'success' && ! $params['toast']);
    expect(fn () => Livewire::test(Impresoras::class)->set('testToken', (string) Str::uuid()))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(Destinos::class)->set('destinoId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('changed printer configuration after confirmation and disabled destinations cannot send', function () {
    $this->mock(TransporteEscPos::class)->shouldNotReceive('enviar');
    $componente = Livewire::test(Impresoras::class)->call('requestTest', $this->impresora->id);
    $this->impresora->update(['host' => '192.168.1.81']);
    $componente->call('confirmTest')->assertDispatched('app:alert', fn ($event, $params) => str_contains($params['text'], 'configuración cambió'));
    $this->cocina->update(['activo' => false]);
    Livewire::test(Impresoras::class)->call('requestTest', $this->impresora->id)->call('confirmTest')
        ->assertDispatched('app:alert', fn ($event, $params) => $params['type'] === 'error');
    expect(PruebaImpresion::count())->toBe(0);
});

test('in flight tests block edits and additional tests while stale tests become uncertain', function () {
    $prueba = PruebaImpresion::create(['id' => (string) Str::uuid(), 'impresora_id' => $this->impresora->id, 'usuario_id' => $this->administrador->id,
        'configuracion' => ['nombre' => 'Cocina', 'host' => '192.168.1.80', 'puerto' => 9100, 'ancho_mm' => 80], 'estado' => 'procesando', 'mensaje' => 'En curso']);
    Livewire::test(Impresoras::class)->call('editRecord', $this->impresora->id)->set('nombre', 'Cambio')->call('save')->assertHasErrors('seguridad');
    $this->mock(TransporteEscPos::class)->shouldNotReceive('enviar');
    Livewire::test(Impresoras::class)->call('requestTest', $this->impresora->id)->call('confirmTest')->assertDispatched('app:alert', fn ($event, $params) => $params['type'] === 'error');
    PruebaImpresion::whereKey($prueba->id)->update(['updated_at' => now()->subMinutes(2)]);
    app(GestionImpresoras::class)->cambiarEstado($this->administrador, $this->impresora->id, false);
    expect($prueba->fresh()->estado)->toBe(EstadoTrabajoImpresion::RESULTADO_INCIERTO);
});

test('pending operational print jobs also block changes to printer and its destination', function () {
    TrabajoImpresion::create(['impresora_id' => $this->impresora->id, 'usuario_id' => $this->administrador->id,
        'tipo' => 'comanda', 'documento_type' => Impresora::class, 'documento_id' => $this->impresora->id,
        'contenido' => [], 'clave_idempotencia' => 'trabajo-pendiente']);
    expect(fn () => app(GestionImpresoras::class)->cambiarEstado($this->administrador, $this->impresora->id, false))->toThrow(ValidationException::class);
    expect(fn () => app(GestionDestinos::class)->cambiarEstado($this->administrador, $this->cocina->id, false))->toThrow(ValidationException::class);
});

test('printer and destination filters reset pagination and revoked permissions block actions', function () {
    Livewire::test(Impresoras::class)->set('usageFilter', 'caja')->assertSee('Impresora Caja 1')->assertDontSee('Editar impresora Impresora Cocina')
        ->set('stateFilter', 'activo')->assertSee('No se encontraron impresoras')->call('clearFilters')->assertSee('Impresora Cocina');
    Livewire::test(Destinos::class)->set('stateFilter', 'inactivo')->assertSee('Barra')->assertDontSee('Editar destino Cocina');
    Livewire::test(Impresoras::class)->call('gotoPage', 2)->set('search', '192.168.1.80')->assertSet('paginators.page', 1)->assertSee('Impresora Cocina');
    $componente = Livewire::test(Impresoras::class)->call('requestTest', $this->impresora->id);
    $this->administrador->roles->first()->revokePermissionTo('impresoras.gestionar');
    $this->administrador->unsetRelations();
    $componente->call('confirmTest')->assertForbidden();
    expect(PruebaImpresion::count())->toBe(0);
});

test('ESC POS transport sends complete bytes over TCP to a local receiver', function () {
    $servidor = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($servidor)->not->toBeFalse();
    try {
        $direccion = stream_socket_get_name($servidor, false);
        $puerto = (int) substr(strrchr($direccion, ':'), 1);
        $contenido = "\x1b@SISREST\n".str_repeat('Texto de prueba\n', 500)."\x1dV\x00";
        app(TransporteEscPos::class)->enviar('127.0.0.1', $puerto, $contenido);
        $cliente = stream_socket_accept($servidor, 2);
        expect($cliente)->not->toBeFalse();
        try {
            stream_set_timeout($cliente, 2);
            expect(stream_get_contents($cliente))->toBe($contenido);
        } finally {
            fclose($cliente);
        }
    } finally {
        fclose($servidor);
    }
});

test('test ticket sanitizes control characters and respects 58 and 80 mm widths', function (int $ancho, int $columnas) {
    $prueba = new PruebaImpresion(['id' => (string) Str::uuid(), 'configuracion' => ['nombre' => "Cocina\x1b\x40\nCaja", 'host' => '192.168.1.90', 'puerto' => 9100, 'ancho_mm' => $ancho]]);
    $prueba->created_at = now();
    $bytes = app(TicketPrueba::class)->generar($prueba);
    expect($bytes)->toContain(str_repeat('-', $columnas)."\n")->toContain('SIN VALOR COMERCIAL')->not->toContain("Cocina\x1b");
})->with([[58, 32], [80, 48]]);
