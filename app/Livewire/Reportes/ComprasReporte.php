<?php

namespace App\Livewire\Reportes;

use App\Models\User;
use App\Services\Reportes\ReporteCompras;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

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
