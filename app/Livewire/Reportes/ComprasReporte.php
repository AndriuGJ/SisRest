<?php

namespace App\Livewire\Reportes;

use App\Exports\ComprasReporteExport;
use App\Models\User;
use App\Services\Configuracion\IdentidadVisual;
use App\Services\Reportes\ReporteCompras;
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
#[Title('Reporte de compras')]
class ComprasReporte extends Component
{
    use WithPagination;

    public string $search = '';

    public string $proveedorId = '';

    public string $stateFilter = '';

    public string $pagoFilter = '';

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
            'proveedor' => $this->proveedorId ?: null,
            'estado' => $this->stateFilter ?: null,
            'pago' => $this->pagoFilter ?: null,
            'buscar' => $this->search,
        ];
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, [
            'search',
            'proveedorId',
            'stateFilter',
            'pagoFilter',
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
            'proveedorId',
            'stateFilter',
            'pagoFilter'
        );

        $this->establecerFechas();

        $this->resetPage();
        $this->resetValidation();
    }

    private function datosExportacion(
        ReporteCompras $reporte,
        IdentidadVisual $identidad,
        int $limite
    ): ?array {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        $reporte->autorizar($actor);

        abort_unless(
            $actor->can('reportes.exportar'),
            403
        );

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

        $consulta = $reporte->consulta($actor, $filtros);

        $compras = (clone $consulta)
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit($limite + 1)
            ->get();

        if ($compras->count() > $limite) {
            $this->addError(
                'exportacion',
                "La exportación admite hasta {$limite} compras. Aplica más filtros."
            );

            return null;
        }

        // Utilizamos exactamente los mismos totales
        // que ya muestra el reporte en pantalla.
        $resumen = $reporte->resumen($consulta);

        $estados = [
            'borrador' => 'Borrador',
            'confirmada' => 'Confirmada',
            'anulada' => 'Anulada',
        ];

        $situaciones = [
            'pendiente' => 'Con saldo pendiente',
            'pagada' => 'Pagadas',
            'vencida' => 'Vencidas',
        ];

        $empresa = $identidad->empresa();

        $hoy = CarbonImmutable::now(
            config('app.timezone')
        );

        $proveedorNombre = 'Todos';

        if (! empty($filtros['proveedor'])) {
            $proveedorNombre = $reporte->proveedores()
                ->firstWhere('id', (int) $filtros['proveedor'])
                ?->nombre ?? 'Proveedor seleccionado';
        }

        return [
            'empresa' => $empresa,
            'nombreEmpresa' => $identidad->nombre(),
            'logo' => $identidad->logoDatos(
                $empresa?->logo_ruta
            ),

            'periodo' => CarbonImmutable::parse(
                $filtros['desde']
            )->format('d/m/Y')
                .' al '.CarbonImmutable::parse(
                    $filtros['hasta']
                )->format('d/m/Y'),

            'proveedorNombre' => $proveedorNombre,

            'estadoNombre' => $estados[$filtros['estado'] ?? ''] ?? 'Todos',

            'pagoNombre' => $situaciones[$filtros['pago'] ?? ''] ?? 'Todas',

            'buscar' => $filtros['buscar'] ?: 'Sin filtro',

            'compras' => $compras,
            'resumen' => $resumen,

            'hoy' => $hoy->toDateString(),
            'fechaEmision' => $hoy->format('d/m/Y H:i'),
            'usuario' => $actor->name,

            'nombreArchivo' => 'reporte-compras-'
                .$filtros['desde'].'_'.$filtros['hasta'],
        ];
    }

    public function exportarPdf(
        ReporteCompras $reporte,
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
            'reportes.pdf.compras-reporte',
            $datos,
            $datos['nombreArchivo'].'.pdf'
        );
    }

    public function exportarExcel(
        ReporteCompras $reporte,
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
            new ComprasReporteExport($datos),
            $datos['nombreArchivo'].'.xlsx'
        );
    }

    public function render(
        ReporteCompras $reporte
    ): View {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        $reporte->autorizar($actor);

        $errorFiltros = null;
        $resumen = null;
        $compras = null;

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

            $compras = (clone $consulta)
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->paginate(10);

        } catch (ValidationException $error) {
            $errorFiltros = $error->validator->errors()->first();
        }

        return view('livewire.reportes.compras-reporte', [
            'compras' => $compras,
            'resumen' => $resumen,
            'errorFiltros' => $errorFiltros,
            'proveedores' => $reporte->proveedores(),
        ]);
    }
}
