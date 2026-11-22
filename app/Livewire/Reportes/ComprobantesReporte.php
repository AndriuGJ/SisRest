<?php

namespace App\Livewire\Reportes;

use App\Exports\ComprobantesReporteExport;
use App\Models\User;
use App\Services\Configuracion\IdentidadVisual;
use App\Services\Reportes\ReporteComprobantes;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
#[Title('Reporte de comprobantes')]
class ComprobantesReporte extends Component
{
    use WithPagination;

    public string $search = '';

    public string $tipoFilter = '';

    public string $stateFilter = '';

    public string $sunatFilter = '';

    public string $entornoFilter = '';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->establecerFechas();
    }

    private function establecerFechas(): void
    {
        $hoy = CarbonImmutable::now(
            config('app.timezone')
        );

        $this->from = $hoy->startOfMonth()->toDateString();
        $this->to = $hoy->toDateString();
    }

    private function filtrosActuales(): array
    {
        return [
            'desde' => $this->from,
            'hasta' => $this->to,
            'buscar' => $this->search,
            'tipo' => $this->tipoFilter ?: null,
            'estado' => $this->stateFilter ?: null,
            'sunat' => $this->sunatFilter ?: null,
            'entorno' => $this->entornoFilter ?: null,
        ];
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, [
            'search',
            'tipoFilter',
            'stateFilter',
            'sunatFilter',
            'entornoFilter',
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
            'tipoFilter',
            'stateFilter',
            'sunatFilter',
            'entornoFilter'
        );

        $this->establecerFechas();

        $this->resetPage();
        $this->resetValidation();
    }

    private function datosExportacion(
        ReporteComprobantes $reporte,
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

        $comprobantes = (clone $consulta)
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->limit($limite + 1)
            ->get();

        if ($comprobantes->count() > $limite) {
            $this->addError(
                'exportacion',
                "La exportación admite hasta {$limite} comprobantes. Aplica más filtros."
            );

            return null;
        }

        $resumen = $reporte->resumen($consulta);
        $empresa = $identidad->empresa();

        $estados = [
            'emitido' => 'Emitidos',
            'anulado' => 'Anulados',
        ];

        $entornos = [
            'beta' => 'Beta',
            'produccion' => 'Producción',
        ];

        $ahora = CarbonImmutable::now(
            config('app.timezone')
        );

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

            'tipoNombre' => $reporte->tipos()[
                $filtros['tipo'] ?? ''
            ] ?? 'Todos',

            'estadoNombre' => $estados[
                $filtros['estado'] ?? ''
            ] ?? 'Todos',

            'sunatNombre' => $reporte->estadosSunat()[
                $filtros['sunat'] ?? ''
            ] ?? 'Todos',

            'entornoNombre' => $entornos[
                $filtros['entorno'] ?? ''
            ] ?? 'Todos',

            'buscar' => $filtros['buscar'] ?: 'Sin filtro',

            'tipos' => $reporte->tipos(),
            'estadosSunat' => $reporte->estadosSunat(),

            'comprobantes' => $comprobantes,
            'resumen' => $resumen,

            'usuario' => $actor->name,
            'fechaEmision' => $ahora->format('d/m/Y H:i'),

            'nombreArchivo' => 'reporte-comprobantes-'
                .$filtros['desde'].'_'.$filtros['hasta'],
        ];
    }

    public function exportarPdf(
        ReporteComprobantes $reporte,
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

        File::ensureDirectoryExists(
            (string) config(
                'dompdf.options.font_cache',
                storage_path('fonts')
            )
        );

        $pdf = Pdf::loadView(
            'reportes.pdf.comprobantes-reporte',
            $datos
        )
            ->setOption('isRemoteEnabled', false)
            ->setPaper('a4', 'landscape');

        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();

        $fuente = $dompdf->getFontMetrics()
            ->getFont('DejaVu Sans', 'normal');

        $canvas->page_text(
            $canvas->get_width() - 135,
            $canvas->get_height() - 24,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $fuente,
            9,
            [0.35, 0.35, 0.35]
        );

        return $this->descargar(
            $pdf->output(),
            $datos['nombreArchivo'].'.pdf',
            'application/pdf'
        );
    }

    public function exportarExcel(
        ReporteComprobantes $reporte,
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

        $contenido = Excel::raw(
            new ComprobantesReporteExport($datos),
            \Maatwebsite\Excel\Excel::XLSX
        );

        return $this->descargar(
            $contenido,
            $datos['nombreArchivo'].'.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    private function descargar(
        string $contenido,
        string $nombre,
        string $tipo
    ): StreamedResponse {
        return response()->streamDownload(
            function () use ($contenido) {
                echo $contenido;
            },
            $nombre,
            [
                'Content-Type' => $tipo,
                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    public function render(
        ReporteComprobantes $reporte
    ): View {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        $reporte->autorizar($actor);

        $errorFiltros = null;
        $resumen = null;
        $comprobantes = null;

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

            $comprobantes = (clone $consulta)
                ->orderByDesc('fecha_emision')
                ->orderByDesc('id')
                ->paginate(10);

        } catch (ValidationException $error) {
            $errorFiltros = $error->validator
                ->errors()
                ->first();
        }

        return view(
            'livewire.reportes.comprobantes-reporte',
            [
                'comprobantes' => $comprobantes,
                'resumen' => $resumen,
                'errorFiltros' => $errorFiltros,
                'tipos' => $reporte->tipos(),
                'estadosSunat' => $reporte->estadosSunat(),
            ]
        );
    }
}
