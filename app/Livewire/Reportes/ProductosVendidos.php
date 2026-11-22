<?php

namespace App\Livewire\Reportes;

use App\Models\User;
use App\Services\Reportes\ReporteProductos;
use App\Services\Reportes\ReporteVentas;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Productos vendidos')]
class ProductosVendidos extends Component
{
    use WithPagination;

    public string $search = '';

    public string $categoriaId = '';

    public string $cajaId = '';

    public string $modalidadFilter = '';

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
            'categoria' => $this->categoriaId ?: null,
            'caja' => $this->cajaId ?: null,
            'modalidad' => $this->modalidadFilter ?: null,
            'tipo' => null,
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
            'categoriaId',
            'cajaId',
            'modalidadFilter',
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
            'categoriaId',
            'cajaId',
            'modalidadFilter'
        );

        $this->establecerFechas();
        $this->resetPage();
    }

    public function render(
        ReporteProductos $reporte,
        ReporteVentas $ventas
    ): View {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403);

        $ventas->autorizar($actor);

        $cajas = $ventas->cajas($actor);
        $categorias = $reporte->categorias();

        $errorFiltros = null;
        $resumen = null;
        $productos = null;

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

            $productos = (clone $consulta)
                ->orderByDesc('cantidad_neta')
                ->orderBy('producto.nombre')
                ->orderBy('producto.id')
                ->paginate(10);

        } catch (ValidationException $error) {
            $errorFiltros = $error->validator->errors()->first();
        }

        return view('livewire.reportes.productos-vendidos', [
            'cajas' => $cajas,
            'categorias' => $categorias,
            'modalidades' => $this->modalidades(),
            'resumen' => $resumen,
            'productos' => $productos,
            'errorFiltros' => $errorFiltros,
        ]);
    }
}
