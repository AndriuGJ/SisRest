<?php

namespace App\Livewire\Reportes;

use App\Exports\InventarioKardexExport;
use App\Models\User;
use App\Services\Configuracion\IdentidadVisual;
use App\Services\Reportes\ReporteInventario;
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
#[Title('Inventario y Kardex')]
class InventarioKardex extends Component
{
    use WithPagination;

    public string $vista = 'existencias';

    public string $search = '';

    public string $tipoArticulo = '';

    public string $stockFilter = '';

    public string $movimientoFilter = '';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $hoy = CarbonImmutable::now(config('app.timezone'));

        $this->from = $hoy->startOfMonth()->toDateString();
        $this->to = $hoy->toDateString();
    }

    private function filtrosActuales(): array
    {
        return [
            'vista' => $this->vista,
            'buscar' => $this->search,
            'tipo_articulo' => $this->tipoArticulo ?: null,
            'disponibilidad' => $this->stockFilter ?: null,
            'tipo_movimiento' => $this->movimientoFilter ?: null,
            'desde' => $this->from,
            'hasta' => $this->to,
        ];
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, [
            'vista',
            'search',
            'tipoArticulo',
            'stockFilter',
            'movimientoFilter',
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
            'tipoArticulo',
            'stockFilter',
            'movimientoFilter'
        );

        $hoy = CarbonImmutable::now(config('app.timezone'));

        $this->from = $hoy->startOfMonth()->toDateString();
        $this->to = $hoy->toDateString();

        $this->resetPage();
        $this->resetValidation();
    }

    private function datosExportacion(
        ReporteInventario $reporte,
        IdentidadVisual $identidad,
        int $limite
    ): ?array {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        $reporte->autorizar($actor);

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

        $esExistencias = $filtros['vista'] === 'existencias';

        if ($esExistencias) {
            $consulta = $reporte->existencias($actor, $filtros);

            $resumen = $reporte->resumenExistencias(
                $actor,
                $consulta
            );

            $registros = (clone $consulta)
                ->orderBy('nombre')
                ->orderBy('id')
                ->limit($limite + 1)
                ->get();
        } else {
            $consulta = $reporte->movimientos($actor, $filtros);

            $resumen = $reporte->resumenMovimientos(
                $actor,
                $consulta
            );

            $registros = (clone $consulta)
                ->orderByDesc('registrado_at')
                ->orderByDesc('id')
                ->limit($limite + 1)
                ->get();
        }

        if ($registros->count() > $limite) {
            $this->addError(
                'exportacion',
                "La exportación admite hasta {$limite} registros. Aplica más filtros."
            );

            return null;
        }

        $empresa = $identidad->empresa();
        $tiposArticulo = $reporte->tiposArticulo();
        $tiposMovimiento = $reporte->tiposMovimiento();

        $disponibilidades = [
            'bajo' => 'Bajo el mínimo',
            'agotado' => 'Sin disponible',
            'con_stock' => 'Con disponible',
        ];

        $nombreArchivo = $esExistencias
            ? 'inventario-existencias-'.now()->format('Y-m-d-His')
            : 'inventario-kardex-'.$filtros['desde']
                .'_'.$filtros['hasta'];

        return [
            'vista' => $filtros['vista'],
            'verCostos' => $actor->can('costos.ver'),

            'titulo' => $esExistencias
                ? 'REPORTE DE EXISTENCIAS'
                : 'REPORTE DE KARDEX',

            'empresa' => $empresa,
            'nombreEmpresa' => $identidad->nombre(),
            'logo' => $identidad->logoDatos($empresa?->logo_ruta),

            'tipoArticuloNombre' => $tiposArticulo[$filtros['tipo_articulo'] ?? '']
                    ?? 'Todos',

            'filtroNombre' => $esExistencias
                ? ($disponibilidades[$filtros['disponibilidad'] ?? '']
                    ?? 'Todas')
                : ($tiposMovimiento[$filtros['tipo_movimiento'] ?? '']
                    ?? 'Todos'),

            'buscar' => $filtros['buscar'] ?: 'Sin filtro',

            'periodo' => $filtros['inicio']->format('d/m/Y')
                .' al '.$filtros['fin']->format('d/m/Y'),

            'tiposArticulo' => $tiposArticulo,
            'tiposMovimiento' => $tiposMovimiento,

            'registros' => $registros,
            'resumen' => $resumen,

            'usuario' => $actor->name,
            'fechaEmision' => now()->format('d/m/Y H:i'),
            'nombreArchivo' => $nombreArchivo,
        ];
    }

    public function exportarPdf(
        ReporteInventario $reporte,
        IdentidadVisual $identidad
    ): ?StreamedResponse {
        $datos = $this->datosExportacion(
            $reporte,
            $identidad,
            2000
        );

        if ($datos === null) {
            return null;
        }

        return app(ExportadorReportes::class)->pdf(
            'reportes.pdf.inventario-kardex',
            $datos,
            $datos['nombreArchivo'].'.pdf'
        );
    }

    public function exportarExcel(
        ReporteInventario $reporte,
        IdentidadVisual $identidad
    ): ?StreamedResponse {
        $datos = $this->datosExportacion(
            $reporte,
            $identidad,
            5000
        );

        if ($datos === null) {
            return null;
        }

        return app(ExportadorReportes::class)->excel(
            new InventarioKardexExport($datos),
            $datos['nombreArchivo'].'.xlsx'
        );
    }

    public function render(ReporteInventario $reporte): View
    {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        $reporte->autorizar($actor);

        $registros = null;
        $resumen = null;
        $errorFiltros = null;

        try {
            $filtros = $reporte->validarFiltros(
                $actor,
                $this->filtrosActuales()
            );

            if ($this->vista === 'existencias') {

                $consulta = $reporte->existencias(
                    $actor,
                    $filtros
                );

                $resumen = $reporte->resumenExistencias(
                    $actor,
                    $consulta
                );

                $registros = (clone $consulta)
                    ->orderBy('nombre')
                    ->orderBy('id')
                    ->paginate(10);

            } else {

                $consulta = $reporte->movimientos(
                    $actor,
                    $filtros
                );

                $resumen = $reporte->resumenMovimientos(
                    $actor,
                    $consulta
                );

                $registros = (clone $consulta)
                    ->orderByDesc('registrado_at')
                    ->orderByDesc('id')
                    ->paginate(10);
            }

        } catch (ValidationException $error) {
            $errorFiltros = $error->validator->errors()->first();
        }

        return view('livewire.reportes.inventario-kardex', [
            'registros' => $registros,
            'resumen' => $resumen,
            'errorFiltros' => $errorFiltros,
            'tiposArticulo' => $reporte->tiposArticulo(),
            'tiposMovimiento' => $reporte->tiposMovimiento(),
            'verCostos' => $actor->can('costos.ver'),
        ]);
    }
}
