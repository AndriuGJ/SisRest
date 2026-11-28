<?php

use App\Livewire\Reportes\CajasArqueos;
use App\Livewire\Reportes\ComprasReporte;
use App\Livewire\Reportes\ComprobantesReporte;
use App\Livewire\Reportes\InventarioKardex;
use App\Livewire\Reportes\ProductosVendidos;
use App\Livewire\Reportes\VentasCobros;
use App\Models\Comprobante;
use App\Models\SerieComprobante;
use App\Models\TipoComprobante;
use App\Models\User;
use App\Services\Reportes\ReporteComprobantes;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\ConfiguracionInicialSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

function pantallasReportesSisRest(): array
{
    return [
        'reportes.ventas' => VentasCobros::class,
        'reportes.productos' => ProductosVendidos::class,
        'reportes.cajas' => CajasArqueos::class,
        'reportes.inventario' => InventarioKardex::class,
        'reportes.compras' => ComprasReporte::class,
        'reportes.comprobantes' => ComprobantesReporte::class,
    ];
}

beforeEach(function () {
    $this->seed([
        RolesPermisosSeeder::class,
        CatalogosSeeder::class,
        ConfiguracionInicialSeeder::class,
    ]);

    $this->actor = User::factory()->create();
    $this->actor->assignRole('administrador');
});

test('las seis rutas requieren autenticación', function () {
    foreach (array_keys(pantallasReportesSisRest()) as $ruta) {
        $this->get(route($ruta))
            ->assertRedirect(route('login'));
    }
});

test('un usuario sin permisos no puede consultar reportes', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario);

    foreach (array_keys(pantallasReportesSisRest()) as $ruta) {
        $this->get(route($ruta))
            ->assertForbidden();
    }
});

test('el administrador puede consultar los seis reportes', function () {
    $this->actingAs($this->actor);

    foreach (array_keys(pantallasReportesSisRest()) as $ruta) {
        $this->get(route($ruta))
            ->assertOk();
    }
});

test('los seis reportes generan PDF y Excel', function () {
    $this->actingAs($this->actor);

    foreach (pantallasReportesSisRest() as $componente) {
        Livewire::test($componente)
            ->call('exportarPdf')
            ->assertHasNoErrors()
            ->assertFileDownloaded();

        Livewire::test($componente)
            ->call('exportarExcel')
            ->assertHasNoErrors()
            ->assertFileDownloaded();
    }
});

test('consultar reportes no concede permiso de exportación', function () {
    $lector = User::factory()->create();

    $lector->givePermissionTo([
        'reportes.ver_todos',
        'inventario.ver',
        'compras.ver',
        'comprobantes.ver',
    ]);

    $this->actingAs($lector);

    foreach (pantallasReportesSisRest() as $componente) {
        Livewire::test($componente)
            ->call('exportarPdf')
            ->assertForbidden();

        Livewire::test($componente)
            ->call('exportarExcel')
            ->assertForbidden();
    }
});

test('un usuario sin permiso de costos no ve valorización', function () {
    $lector = User::factory()->create();

    $lector->givePermissionTo([
        'reportes.ver_todos',
        'inventario.ver',
    ]);

    $this->actingAs($lector)
        ->get(route('reportes.inventario'))
        ->assertOk()
        ->assertDontSee('Valorización actual');
});

test('los importes beta y producción se calculan por separado', function () {
    $tipo = TipoComprobante::query()
        ->where('codigo', '01')
        ->firstOrFail();

    $serieBeta = SerieComprobante::query()
        ->where('tipo_comprobante_id', $tipo->id)
        ->where('entorno', 'beta')
        ->firstOrFail();

    $serieProduccion = SerieComprobante::create([
        'tipo_comprobante_id' => $tipo->id,
        'entorno' => 'produccion',
        'serie' => 'F001',
        'ultimo_correlativo' => 0,
        'activo' => true,
    ]);

    $crear = function (
        SerieComprobante $serie,
        int $correlativo,
        string $estado,
        string $estadoSunat,
        string $total
    ) {
        $comprobante = Comprobante::create([
            'serie_comprobante_id' => $serie->id,
            'correlativo' => $correlativo,
            'entorno' => $serie->entorno,
            'usuario_id' => $this->actor->id,
            'fecha_emision' => now()->toDateString(),
            'moneda' => 'PEN',
            'estado' => $estado,
            'estado_sunat' => $estadoSunat,
            'emisor' => ['ruc' => '20123456789'],
            'receptor' => [
                'nombre' => 'Cliente de prueba',
                'numero' => '12345678',
            ],
            'configuracion_tributaria' => [
                'tipo' => '01',
                'serie' => $serie->serie,
                'entorno' => $serie->entorno,
            ],
            'valor_venta' => $total,
            'total' => $total,
            'clave_idempotencia' => (string) Str::uuid(),
        ]);
        DB::table('comprobantes')
            ->where('id', $comprobante->id)
            ->update([
                'fecha_emision' => now()->toDateString(),
            ]);

        return $comprobante;
    };

    $crear($serieBeta, 1, 'emitido', 'rechazado', '100.00');
    $crear($serieProduccion, 1, 'emitido', 'aceptado', '200.00');
    $crear($serieProduccion, 2, 'anulado', 'aceptado', '50.00');

    $reporte = app(ReporteComprobantes::class);

    $filtros = $reporte->validarFiltros(
        $this->actor,
        [
            'desde' => now()->toDateString(),
            'hasta' => now()->toDateString(),
            'buscar' => '',
        ]
    );

    $resumen = $reporte->resumen(
        $reporte->consulta($this->actor, $filtros)
    );

    expect($resumen['documentos'])->toBe(3);

    expect(bccomp(
        $resumen['importe_beta'],
        '100.00',
        2
    ))->toBe(0);

    expect(bccomp(
        $resumen['importe_produccion'],
        '200.00',
        2
    ))->toBe(0);
});
