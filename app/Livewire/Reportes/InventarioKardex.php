<?php

namespace App\Livewire\Reportes;

use App\Models\User;
use App\Services\Reportes\ReporteInventario;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

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
