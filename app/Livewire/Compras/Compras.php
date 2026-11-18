<?php

namespace App\Livewire\Compras;

use App\Models\Compra;
use App\Models\Proveedor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Compras')]
class Compras extends Component
{
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Url(as: 'proveedor', except: '')]
    public string $proveedorId = '';

    #[Url(as: 'saldo', except: '')]
    public string $saldoFilter = '';

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $to = '';

    public function mount(): void
    {
        Gate::authorize('compras.ver');
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, ['search', 'stateFilter', 'proveedorId', 'saldoFilter', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'stateFilter', 'proveedorId', 'saldoFilter', 'from', 'to');
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('compras.ver');
        $query = Compra::with(['proveedor', 'pagos'])->latest('fecha')->latest('id');
        $buscar = trim(mb_substr($this->search, 0, 100));
        if ($buscar !== '') {
            $query->where(fn (Builder $q) => $q->where('codigo', 'like', '%'.$buscar.'%')->orWhere('proveedor_nombre', 'like', '%'.$buscar.'%')->orWhere('numero', 'like', '%'.$buscar.'%'));
        }
        if (in_array($this->stateFilter, ['borrador', 'confirmada', 'anulada'], true)) {
            $query->where('estado', $this->stateFilter);
        }
        if (ctype_digit($this->proveedorId)) {
            $query->where('proveedor_id', $this->proveedorId);
        }
        if (in_array($this->saldoFilter, ['pendiente', 'pagada'], true)) {
            $query->where('estado', 'confirmada')->whereRaw('(compras.total - COALESCE((SELECT SUM(pagos_compra.monto) FROM pagos_compra WHERE pagos_compra.compra_id = compras.id), 0)) '.($this->saldoFilter === 'pendiente' ? '> 0' : '= 0'));
        }
        foreach (['from' => '>=', 'to' => '<='] as $campo => $operador) {
            if ($this->$campo !== '' && Validator::make(['fecha' => $this->$campo], ['fecha' => ['date_format:Y-m-d']])->passes()) {
                $query->whereDate('fecha', $operador, $this->$campo);
            }
        }

        return view('livewire.compras.compras', ['compras' => $query->paginate(15), 'proveedores' => Proveedor::orderBy('nombre')->get(['id', 'nombre'])]);
    }
}
