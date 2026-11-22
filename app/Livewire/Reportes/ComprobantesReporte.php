<?php

namespace App\Livewire\Reportes;

use App\Models\User;
use App\Services\Reportes\ReporteComprobantes;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

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
