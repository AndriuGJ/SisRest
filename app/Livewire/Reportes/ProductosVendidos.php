<?php

namespace App\Livewire\Reportes;

use App\Exports\ProductosVendidosExport;
use App\Models\User;
use App\Services\Configuracion\IdentidadVisual;
use App\Services\Reportes\ReporteProductos;
use App\Services\Reportes\ReporteVentas;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\Reportes\ExportadorReportes;

#[Layout('layouts.app')]
#[Title('Productos vendidos')]
class ProductosVendidos extends Component
{
    use WithPagination;

    public string $search = '';

    public string $categoriaId = '';

    public string $cajaId = '';

    public string $modalidadFilter = '';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->establecerFechas();
    }

    private function establecerFechas(): void
    {
        $hoy = CarbonImmutable::now(config('app.timezone'));

        $this->from = $hoy->startOfMonth()->toDateString();
        $this->to = $hoy->toDateString();
    }

    private function filtrosActuales(): array
    {
        return [
            'desde' => $this->from,
            'hasta' => $this->to,
            'categoria' => $this->categoriaId ?: null,
            'caja' => $this->cajaId ?: null,
            'modalidad' => $this->modalidadFilter ?: null,
            'tipo' => null,
            'buscar' => $this->search,
        ];
    }

    private function modalidades(): array
    {
        return [
            'mesa' => 'Mesas',
            'rapida' => 'Venta rápida',
            'delivery' => 'Delivery',
        ];
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, [
            'search',
            'categoriaId',
            'cajaId',
            'modalidadFilter',
            'from',
            'to',
        ], true)) {
            $this->resetPage();
            $this->resetValidation('exportacion');
        }
    }

    public function clearFilters(): void
    {
        $this->reset(
            'search',
            'categoriaId',
            'cajaId',
            'modalidadFilter'
        );

        $this->establecerFechas();
        $this->resetPage();
        $this->resetValidation();
    }

    private function datosExportacion(
        ReporteProductos $reporte,
        ReporteVentas $ventas,
        IdentidadVisual $identidad,
        int $limite
    ): ?array {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        $ventas->autorizar($actor);

        abort_unless($actor->can('reportes.exportar'), 403);

        $this->resetValidation('exportacion');

        try {
            $filtros = $reporte->validarFiltros(
                $actor,
                $this->filtrosActuales()
            );
        } catch (ValidationException $error) {
            $this->addError(
                'exportacion',
                $error->validator->errors()->first()
            );

            return null;
        }

        $productos = $reporte->consulta($actor, $filtros)
            ->orderByDesc('cantidad_neta')
            ->orderBy('producto.nombre')
            ->orderBy('producto.id')
            ->limit($limite + 1)
            ->get();

        if ($productos->count() > $limite) {
            $this->addError(
                'exportacion',
                "La exportación admite hasta {$limite} productos. Aplica más filtros."
            );

            return null;
        }

        $resumen = [
            'productos' => $productos->count(),
            'cantidad_neta' => '0.0000',
            'descuento' => '0.00',
            'importe_neto' => '0.00',
        ];

        foreach ($productos as $producto) {
            $resumen['cantidad_neta'] = bcadd(
                $resumen['cantidad_neta'],
                (string) $producto->cantidad_neta,
                4
            );

            $resumen['descuento'] = bcadd(
                $resumen['descuento'],
                (string) $producto->descuento,
                2
            );

            $resumen['importe_neto'] = bcadd(
                $resumen['importe_neto'],
                (string) $producto->importe_neto,
                2
            );
        }

        $empresa = $identidad->empresa();
        $modalidades = $this->modalidades();

        return [
            'empresa' => $empresa,
            'nombreEmpresa' => $identidad->nombre(),
            'logo' => $identidad->logoDatos($empresa?->logo_ruta),

            'filtros' => $filtros,

            'periodo' => $filtros['inicio']->format('d/m/Y')
                .' al '.$filtros['fin']->format('d/m/Y'),

            'cajaNombre' => empty($filtros['caja'])
                ? 'Todas las autorizadas'
                : ($ventas->cajas($actor)
                    ->firstWhere('id', $filtros['caja'])
                    ?->nombre ?? 'Caja seleccionada'),

            'categoriaNombre' => empty($filtros['categoria'])
                ? 'Todas'
                : ($reporte->categorias()
                    ->firstWhere('id', $filtros['categoria'])
                    ?->nombre ?? 'Categoría seleccionada'),

            'modalidadNombre' => $modalidades[$filtros['modalidad'] ?? ''] ?? 'Todas',

            'buscar' => $filtros['buscar'] ?: 'Sin filtro',

            'productos' => $productos,
            'resumen' => $resumen,

            'usuario' => $actor->name,
            'fechaEmision' => now()->format('d/m/Y H:i'),

            'nombreArchivo' => 'productos-vendidos-'
                .$filtros['desde'].'_'.$filtros['hasta'],
        ];
    }

    public function exportarPdf(
        ReporteProductos $reporte,
        ReporteVentas $ventas,
        IdentidadVisual $identidad
    ): ?StreamedResponse {
        $datos = $this->datosExportacion(
            $reporte,
            $ventas,
            $identidad,
            2000
        );

        if ($datos === null) {
            return null;
        }

        return app(ExportadorReportes::class)->pdf(
            'reportes.pdf.productos-vendidos',
            $datos,
            $datos['nombreArchivo'].'.pdf'
        );
    }

    public function exportarExcel(
        ReporteProductos $reporte,
        ReporteVentas $ventas,
        IdentidadVisual $identidad
    ): ?StreamedResponse {
        $datos = $this->datosExportacion(
            $reporte,
            $ventas,
            $identidad,
            5000
        );

        if ($datos === null) {
            return null;
        }

        return app(ExportadorReportes::class)->excel(
            new ProductosVendidosExport($datos),
            $datos['nombreArchivo'].'.xlsx'
        );
    }

    public function render(
        ReporteProductos $reporte,
        ReporteVentas $ventas
    ): View {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        $ventas->autorizar($actor);

        $cajas = $ventas->cajas($actor);
        $categorias = $reporte->categorias();

        $errorFiltros = null;
        $resumen = null;
        $productos = null;

        try {
            $filtros = $reporte->validarFiltros(
                $actor,
                $this->filtrosActuales()
            );

            $consulta = $reporte->consulta(
                $actor,
                $filtros
            );

            $resumen = $reporte->resumen($consulta);

            $productos = (clone $consulta)
                ->orderByDesc('cantidad_neta')
                ->orderBy('producto.nombre')
                ->orderBy('producto.id')
                ->paginate(10);

        } catch (ValidationException $error) {
            $errorFiltros = $error->validator->errors()->first();
        }

        return view('livewire.reportes.productos-vendidos', [
            'cajas' => $cajas,
            'categorias' => $categorias,
            'modalidades' => $this->modalidades(),
            'resumen' => $resumen,
            'productos' => $productos,
            'errorFiltros' => $errorFiltros,
        ]);
    }
}
