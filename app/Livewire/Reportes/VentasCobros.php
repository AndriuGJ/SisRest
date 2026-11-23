<?php

namespace App\Livewire\Reportes;

use App\Exports\VentasCobrosExport;
use App\Models\User;
use App\Services\Configuracion\IdentidadVisual;
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

        return app(ExportadorReportes::class)->pdf(
            'reportes.pdf.ventas-cobros',
            [
                'empresa' => $empresa,
                'nombreEmpresa' => $identidad->nombre(),
                'logo' => $identidad->logoDatos(
                    $empresa?->logo_ruta
                ),
                'filtros' => $filtros,
                'cajaNombre' => $cajaNombre,
                'modalidades' => $this->modalidades(),
                'resumen' => $resumen,
                'operaciones' => $operaciones,
                'usuario' => $actor->name,
                'fechaEmision' => now()->format('d/m/Y H:i'),
            ],
            'ventas-cobros-'
                .$filtros['desde'].'_'.$filtros['hasta'].'.pdf'
        );

    }

    public function exportarExcel(
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

        $operaciones = $reporte->consulta($actor, $filtros)
            ->orderByDesc('fecha')
            ->orderBy('tipo')
            ->orderByDesc('operacion_id')
            ->limit(5001)
            ->get();

        if ($operaciones->count() > 5000) {

            $this->addError(
                'exportacion',
                'El Excel admite hasta 5000 operaciones. Reduce el período o aplica más filtros.'
            );

            return null;
        }

        $modalidades = $this->modalidades();

        $tipos = [
            'cobro' => 'Cobros',
            'devolucion' => 'Devoluciones',
        ];

        $cajaNombre = empty($filtros['caja'])
            ? 'Todas las autorizadas'
            : ($reporte->cajas($actor)
                ->firstWhere('id', $filtros['caja'])
                ?->nombre ?? 'Caja seleccionada');

        $datos = [
            'empresa' => $identidad->nombre(),

            'ruc' => $identidad->empresa()?->ruc ?? '—',

            'periodo' => $filtros['inicio']->format('d/m/Y')
                .' al '.$filtros['fin']->format('d/m/Y'),

            'caja' => $cajaNombre,

            'modalidad' => $modalidades[$filtros['modalidad'] ?? '']
                ?? 'Todas',

            'tipo' => $tipos[$filtros['tipo'] ?? '']
                ?? 'Todas',

            'buscar' => $filtros['buscar'] ?: 'Sin filtro',

            'usuario' => $actor->name,

            'fecha' => now()->format('d/m/Y H:i'),
        ];

        return app(ExportadorReportes::class)->excel(
            new VentasCobrosExport(
                $operaciones,
                $datos,
                $modalidades
            ),
            'ventas-cobros-'
                .$filtros['desde'].'_'.$filtros['hasta'].'.xlsx'
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
