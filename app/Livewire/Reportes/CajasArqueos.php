<?php

namespace App\Livewire\Reportes;

use App\Exports\CajasArqueosExport;
use App\Models\User;
use App\Services\Configuracion\IdentidadVisual;
use App\Services\Reportes\ReporteCajas;
use App\Services\Reportes\ReporteVentas;
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
#[Title('Reporte de cajas y arqueos')]
class CajasArqueos extends Component
{
    use WithPagination;

    public string $search = '';

    public string $cajaId = '';

    public string $stateFilter = '';

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
            'caja' => $this->cajaId ?: null,
            'estado' => $this->stateFilter ?: null,
            'modalidad' => null,
            'tipo' => null,
            'buscar' => $this->search,
        ];
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, [
            'search',
            'cajaId',
            'stateFilter',
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
            'cajaId',
            'stateFilter'
        );

        $this->establecerFechas();
        $this->resetPage();
        $this->resetValidation();
    }

    private function datosExportacion(
        ReporteCajas $reporte,
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

        $turnos = $reporte->consulta($actor, $filtros)
            ->orderByDesc('abierta_at')
            ->orderByDesc('id')
            ->limit($limite + 1)
            ->get();

        if ($turnos->count() > $limite) {
            $this->addError(
                'exportacion',
                "La exportación admite hasta {$limite} turnos. Aplica más filtros."
            );

            return null;
        }

        $resumen = [
            'turnos' => $turnos->count(),
            'abiertas' => 0,
            'cerradas' => 0,
            'neto_cobrado' => '0.00',
            'faltantes' => '0.00',
            'sobrantes' => '0.00',
        ];

        foreach ($turnos as $turno) {

            $resumen['neto_cobrado'] = bcadd(
                $resumen['neto_cobrado'],
                (string) $turno->neto_cobrado,
                2
            );

            if ($turno->estado === 'abierta') {
                $resumen['abiertas']++;

                continue;
            }

            $resumen['cerradas']++;

            if ($turno->diferencia === null) {
                continue;
            }

            $diferencia = (string) $turno->diferencia;

            if (bccomp($diferencia, '0', 2) < 0) {
                $resumen['faltantes'] = bcsub(
                    $resumen['faltantes'],
                    $diferencia,
                    2
                );
            }

            if (bccomp($diferencia, '0', 2) > 0) {
                $resumen['sobrantes'] = bcadd(
                    $resumen['sobrantes'],
                    $diferencia,
                    2
                );
            }
        }

        $empresa = $identidad->empresa();

        $estados = [
            'abierta' => 'Abiertas',
            'cerrada' => 'Cerradas',
        ];

        return [
            'empresa' => $empresa,
            'nombreEmpresa' => $identidad->nombre(),
            'logo' => $identidad->logoDatos($empresa?->logo_ruta),

            'periodo' => $filtros['inicio']->format('d/m/Y')
                .' al '.$filtros['fin']->format('d/m/Y'),

            'cajaNombre' => empty($filtros['caja'])
                ? 'Todas las autorizadas'
                : ($ventas->cajas($actor)
                    ->firstWhere('id', $filtros['caja'])
                    ?->nombre ?? 'Caja seleccionada'),

            'estadoNombre' => $estados[$filtros['estado'] ?? ''] ?? 'Todos',

            'buscar' => $filtros['buscar'] !== ''
                ? $filtros['buscar']
                : 'Sin filtro',

            'turnos' => $turnos,
            'resumen' => $resumen,

            'usuario' => $actor->name,
            'fechaEmision' => now()->format('d/m/Y H:i'),

            'nombreArchivo' => 'cajas-arqueos-'
                .$filtros['desde'].'_'.$filtros['hasta'],
        ];
    }

    public function exportarPdf(
        ReporteCajas $reporte,
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

        File::ensureDirectoryExists(
            (string) config(
                'dompdf.options.font_cache',
                storage_path('fonts')
            )
        );

        $pdf = Pdf::loadView(
            'reportes.pdf.cajas-arqueos',
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
        ReporteCajas $reporte,
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

        $contenido = Excel::raw(
            new CajasArqueosExport($datos),
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
        ReporteCajas $reporte,
        ReporteVentas $ventas
    ): View {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        $ventas->autorizar($actor);

        $cajas = $ventas->cajas($actor);

        $errorFiltros = null;
        $resumen = null;
        $turnos = null;

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

            $turnos = (clone $consulta)
                ->orderByDesc('abierta_at')
                ->orderByDesc('id')
                ->paginate(10);

        } catch (ValidationException $error) {
            $errorFiltros = $error->validator->errors()->first();
        }

        return view('livewire.reportes.cajas-arqueos', [
            'cajas' => $cajas,
            'resumen' => $resumen,
            'turnos' => $turnos,
            'errorFiltros' => $errorFiltros,
        ]);
    }
}
