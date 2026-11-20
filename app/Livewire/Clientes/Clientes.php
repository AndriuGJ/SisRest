<?php

namespace App\Livewire\Clientes;

use App\Concerns\ConAlertas;
use App\Models\Cliente;
use App\Models\User;
use App\Services\Clientes\GestionClientes;
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
#[Title('Clientes')]
class Clientes extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Url(as: 'tipo', except: '')]
    public string $typeFilter = '';

    #[Locked]
    public ?int $stateId = null;

    #[Locked]
    public bool $nextState = false;

    #[Locked]
    public string $version = '';

    public function mount(): void
    {
        $this->actor();
    }

    private function actor(): User
    {
        $usuario = Auth::user();
        abort_unless($usuario instanceof User, 403);
        $actual = User::findOrFail($usuario->id);
        abort_unless($actual->activo, 403);
        Gate::forUser($actual)->authorize('clientes.ver');

        return $actual;
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, ['search', 'stateFilter', 'typeFilter'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'stateFilter', 'typeFilter');
        $this->resetPage();
    }

    public function requestStateChange(int $id): void
    {
        Gate::forUser($this->actor())->authorize('clientes.editar');
        $cliente = Cliente::findOrFail($id);
        $this->stateId = $id;
        $this->nextState = ! $cliente->activo;
        $this->version = GestionClientes::huella($cliente);
        $this->confirmar($this->nextState ? 'Activar cliente' : 'Desactivar cliente',
            $cliente->nombre.'. Se conservarán sus datos y su historial.', 'confirmState', $this->nextState ? 'Activar cliente' : 'Desactivar cliente', peligro: ! $this->nextState);
    }

    public function confirmState(GestionClientes $gestion): void
    {
        abort_if($this->stateId === null, 403);
        try {
            $gestion->cambiarEstado($this->actor(), $this->stateId, $this->nextState, $this->version);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->notificar('Estado del cliente actualizado.');
    }

    public function render(): View
    {
        $this->actor();
        $buscar = trim(mb_substr($this->search, 0, 100));
        $query = Cliente::with('tipoDocumento')->orderBy('nombre')->orderBy('id');
        if ($buscar !== '') {
            $query->where(fn (Builder $q) => $q->where('nombre', 'like', '%'.$buscar.'%')->orWhere('numero_documento', 'like', '%'.$buscar.'%')
                ->orWhere('telefono', 'like', '%'.$buscar.'%')->orWhere('correo', 'like', '%'.$buscar.'%'));
        }
        if (in_array($this->stateFilter, ['activo', 'inactivo'], true)) {
            $query->where('activo', $this->stateFilter === 'activo');
        }
        if (in_array($this->typeFilter, ['persona', 'empresa'], true)) {
            $query->where('tipo', $this->typeFilter);
        }

        return view('livewire.clientes.clientes', ['clientes' => $query->paginate(15)]);
    }
}
