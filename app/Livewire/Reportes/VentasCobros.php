<?php

namespace App\Livewire\Reportes;

use App\Models\User;
use App\Services\Reportes\ReporteVentas;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

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
        $hoy = CarbonImmutable::now(
            config('app.timezone')
        );

        $this->from = $hoy->startOfMonth()->toDateString();
        $this->to = $hoy->toDateString();
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
    }

    public function render(ReporteVentas $reporte): View
    {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        $reporte->autorizar($actor);

        $cajas = $reporte->cajas($actor);

        $modalidades = [
            'mesa' => 'Mesas',
            'rapida' => 'Venta rápida',
            'delivery' => 'Delivery',
        ];

        $hoy = CarbonImmutable::now(
            config('app.timezone')
        );

        $filtros = [
            'desde' => $this->from,
            'hasta' => $this->to,
            'caja' => $this->cajaId ?: null,
            'modalidad' => $this->modalidadFilter ?: null,
            'tipo' => $this->tipoFilter ?: null,
            'buscar' => $this->search,
        ];

        $validador = Validator::make($filtros, [
            'desde' => [
                'required',
                'date_format:Y-m-d',
            ],
            'hasta' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:desde',
                'before_or_equal:'.$hoy->toDateString(),
            ],
            'caja' => [
                'nullable',
                'integer',
                Rule::in($cajas->pluck('id')->all()),
            ],
            'modalidad' => [
                'nullable',
                Rule::in(array_keys($modalidades)),
            ],
            'tipo' => [
                'nullable',
                Rule::in(['cobro', 'devolucion']),
            ],
        ]);

        $errorFiltros = null;
        $resumen = null;
        $operaciones = null;

        if ($validador->fails()) {
            $errorFiltros = 'Selecciona fechas y filtros válidos.';
        } else {

            $inicio = CarbonImmutable::parse(
                $this->from,
                config('app.timezone')
            )->startOfDay();

            $hasta = CarbonImmutable::parse(
                $this->to,
                config('app.timezone')
            )->startOfDay();

            if ($inicio->diffInDays($hasta) >= 90) {

                $errorFiltros =
                    'Selecciona un período máximo de 90 días.';

            } else {

                $filtros['inicio'] = $inicio;
                $filtros['fin'] = $hasta->endOfDay();

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
            }
        }

        return view('livewire.reportes.ventas-cobros', [
            'cajas' => $cajas,
            'modalidades' => $modalidades,
            'resumen' => $resumen,
            'operaciones' => $operaciones,
            'errorFiltros' => $errorFiltros,
        ]);
    }
}
