<?php

namespace App\Livewire\Inventario;

use App\Concerns\ConAlertas;
use App\Models\Articulo;
use App\Models\User;
use App\Services\Inventario\GestionInsumos;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Insumos')]
class Insumos extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Url(as: 'existencias', except: '')]
    public string $stockFilter = '';

    public function updatedStockFilter(): void
    {
        $this->resetPage();
    }

    #[Locked]
    public ?int $stateId = null;

    #[Locked]
    public bool $nextState = false;

    public function mount(): void
    {
        Gate::authorize('inventario.ver');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStateFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'stateFilter', 'stockFilter');
        $this->resetPage();
    }

    public function requestStateChange(int $id): void
    {
        Gate::authorize('insumos.editar');
        $registro = Articulo::query()->where('tipo', 'insumo')->findOrFail($id);
        $this->resetValidation();
        $this->stateId = $id;
        $this->nextState = ! $registro->activo;
        $this->confirmar($this->nextState ? 'Activar insumo' : 'Desactivar insumo',
            $registro->nombre.'. Se conserva el historial y las existencias.', 'confirmState',
            $this->nextState ? 'Activar insumo' : 'Desactivar insumo', peligro: ! $this->nextState);
    }

    public function confirmState(GestionInsumos $gestion): void
    {
        abort_if($this->stateId === null, 404);
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        try {
            $gestion->cambiarEstado($actor, $this->stateId, $this->nextState);
        } catch (ValidationException $error) {
            $this->setErrorBag($error->validator->errors());
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->notificar($this->nextState ? 'Insumo activado.' : 'Insumo desactivado.');
        $this->reset('stateId');
    }

    public function render(): View
    {
        Gate::authorize('inventario.ver');
        $consulta = Articulo::query()->where('tipo', 'insumo')->with('unidadMedida')->orderBy('nombre')->orderBy('id');
        $buscar = trim(mb_substr($this->search, 0, 100));
        if ($buscar !== '') {
            $consulta->where(fn (Builder $query) => $query->where('nombre', 'like', '%'.$buscar.'%')->orWhere('codigo', 'like', '%'.$buscar.'%'));
        }
        if ($this->stockFilter === 'bajo') {
            $consulta->where('stock_minimo', '>', 0)->whereRaw('stock_actual - stock_reservado <= stock_minimo');
        }
        if (in_array($this->stateFilter, ['activo', 'inactivo'], true)) {
            $consulta->where('activo', $this->stateFilter === 'activo');
        }

        return view('livewire.inventario.insumos', [
            'insumos' => $consulta->paginate(15),
        ]);
    }
}
