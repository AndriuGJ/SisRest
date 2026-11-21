<?php

namespace App\Livewire\Reportes;

use App\Models\User;
use App\Services\Configuracion\IdentidadVisual;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
#[Title('Ventas y cobros')]
class VentasCobros extends Component
{
    use WithPagination;

    public string $search = '';

    public string $cajaId = '';

    public string $modalidadFilter = '';

    public string $tipoFilter = '';

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
            'modalidad' => $this->modalidadFilter ?: null,
            'tipo' => $this->tipoFilter ?: null,
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
            'cajaId',
            'modalidadFilter',
            'tipoFilter',
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
            'modalidadFilter',
            'tipoFilter'
        );

        $this->establecerFechas();

        $this->resetPage();
        $this->resetValidation();
    }

    public function exportarPdf(
        ReporteVentas $reporte,
        IdentidadVisual $identidad
    ): ?StreamedResponse {
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

        $operaciones = (clone $consulta)
            ->orderByDesc('fecha')
            ->orderBy('tipo')
            ->orderByDesc('operacion_id')
            ->limit(2001)
            ->get();

        if ($operaciones->count() > 2000) {
            $this->addError(
                'exportacion',
                'El PDF admite hasta 2000 operaciones. Reduce el período o aplica más filtros.'
            );

            return null;
        }

        // Calcular los totales de las operaciones exportadas.
        $cobrado = '0.00';
        $devuelto = '0.00';

        foreach ($operaciones as $operacion) {
            if ($operacion->tipo === 'cobro') {
                $cobrado = bcadd(
                    $cobrado,
                    (string) $operacion->importe,
                    2
                );
            } else {
                $devuelto = bcsub(
                    $devuelto,
                    (string) $operacion->importe,
                    2
                );
            }
        }

        $resumen = [
            'cobrado' => $cobrado,
            'devuelto' => $devuelto,
            'neto' => bcsub($cobrado, $devuelto, 2),
            'operaciones' => $operaciones->count(),
        ];

        $empresa = $identidad->empresa();

        $cajaNombre = empty($filtros['caja'])
            ? 'Todas las autorizadas'
            : ($reporte->cajas($actor)
                ->firstWhere('id', $filtros['caja'])
                ?->nombre ?? 'Caja seleccionada');

        File::ensureDirectoryExists(
            (string) config(
                'dompdf.options.font_cache',
                storage_path('fonts')
            )
        );

        $pdf = Pdf::loadView('reportes.pdf.ventas-cobros', [
            'empresa' => $empresa,
            'nombreEmpresa' => $identidad->nombre(),
            'logo' => $identidad->logoDatos($empresa?->logo_ruta),
            'filtros' => $filtros,
            'cajaNombre' => $cajaNombre,
            'modalidades' => $this->modalidades(),
            'resumen' => $resumen,
            'operaciones' => $operaciones,
            'usuario' => $actor->name,
            'fechaEmision' => now()->format('d/m/Y H:i'),
        ])
            ->setOption('isRemoteEnabled', false)
            ->setPaper('a4', 'landscape');

        // Numeración de páginas.
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

        $nombreArchivo = 'ventas-cobros-'.$filtros['desde']
    .'_'.$filtros['hasta'].'.pdf';

        $contenido = $pdf->output();

        return response()->streamDownload(
            function () use ($contenido) {
                echo $contenido;
            },
            $nombreArchivo,
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    public function render(ReporteVentas $reporte): View
    {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        $reporte->autorizar($actor);

        $cajas = $reporte->cajas($actor);

        $errorFiltros = null;
        $resumen = null;
        $operaciones = null;

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

            $operaciones = (clone $consulta)
                ->orderByDesc('fecha')
                ->orderBy('tipo')
                ->orderByDesc('operacion_id')
                ->paginate(10);

        } catch (ValidationException $error) {
            $errorFiltros = $error->validator->errors()->first();
        }

        return view('livewire.reportes.ventas-cobros', [
            'cajas' => $cajas,
            'modalidades' => $this->modalidades(),
            'resumen' => $resumen,
            'operaciones' => $operaciones,
            'errorFiltros' => $errorFiltros,
        ]);
    }
}
