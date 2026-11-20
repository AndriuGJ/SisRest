<?php

namespace App\Livewire\Compras;

use App\Concerns\ConAlertas;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Compras\GestionProveedores;
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
#[Title('Proveedores')]
class Proveedores extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Locked]
    public ?int $stateId = null;

    #[Locked]
    public bool $nextState = false;

    #[Locked]
    public string $version = '';

    public function mount(): void
    {
        Gate::authorize('proveedores.gestionar');
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, ['search', 'stateFilter'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'stateFilter');
        $this->resetPage();
    }

    public function requestStateChange(int $id): void
    {
        Gate::authorize('proveedores.gestionar');
        $proveedor = Proveedor::findOrFail($id);
        $this->stateId = $id;
        $this->nextState = ! $proveedor->activo;
        $this->version = GestionProveedores::huella($proveedor);
        $this->confirmar($this->nextState ? 'Activar proveedor' : 'Desactivar proveedor', $proveedor->nombre.'. Sus compras y pagos se conservarán.', 'confirmState', 'Confirmar', peligro: ! $this->nextState);
    }

    public function confirmState(GestionProveedores $gestion): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && $this->stateId, 403);
        try {
            $gestion->cambiarEstado($actor, $this->stateId, $this->nextState, $this->version);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->notificar('Estado del proveedor actualizado.');
    }

    public function render(): View
    {
        Gate::authorize('proveedores.gestionar');
        $buscar = trim(mb_substr($this->search, 0, 100));
        $query = Proveedor::with('tipoDocumento')->orderBy('nombre');
        if ($buscar !== '') {
            $query->where(fn (Builder $q) => $q->where('nombre', 'like', '%'.$buscar.'%')->orWhere('numero_documento', 'like', '%'.$buscar.'%'));
        }
        if (in_array($this->stateFilter, ['activo', 'inactivo'], true)) {
            $query->where('activo', $this->stateFilter === 'activo');
        }

        return view('livewire.compras.proveedores', ['proveedores' => $query->paginate(15)]);
    }
}
