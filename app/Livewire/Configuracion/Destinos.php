<?php

namespace App\Livewire\Configuracion;

use App\Concerns\ConAlertas;
use App\Models\AreaPreparacion;
use App\Models\User;
use App\Services\Configuracion\GestionDestinos;
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
#[Title('Destinos de preparación')]
class Destinos extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Locked]
    public ?int $destinoId = null;

    public bool $showForm = false;

    public string $codigo = '';

    public string $nombre = '';

    public bool $activo = true;

    #[Locked]
    public ?int $stateId = null;

    #[Locked]
    public bool $nextState = false;

    public function mount(): void
    {
        Gate::authorize('configuracion.restaurante');
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
        $this->reset('search', 'stateFilter');
        $this->resetPage();
    }

    public function newRecord(): void
    {
        Gate::authorize('configuracion.restaurante');
        $this->resetValidation();
        $this->reset('destinoId', 'codigo', 'nombre', 'activo');
        $this->showForm = true;
    }

    public function editRecord(int $id): void
    {
        Gate::authorize('configuracion.restaurante');
        $registro = AreaPreparacion::query()->findOrFail($id);
        $this->resetValidation();
        $this->destinoId = $registro->id;
        $this->codigo = $registro->codigo;
        $this->nombre = $registro->nombre;
        $this->activo = $registro->activo;
        $this->showForm = true;
    }

    public function save(GestionDestinos $gestion): void
    {
        $gestion->guardar($this->actor(), $this->destinoId, [
            'codigo' => $this->codigo, 'nombre' => $this->nombre, 'activo' => $this->activo,
        ]);
        $this->showForm = false;
        $this->notificar($this->destinoId ? 'Destino actualizado.' : 'Destino registrado.');
    }

    public function requestStateChange(int $id): void
    {
        Gate::authorize('configuracion.restaurante');
        $registro = AreaPreparacion::query()->findOrFail($id);
        $this->resetValidation();
        $this->stateId = $registro->id;
        $this->nextState = ! $registro->activo;
        $this->confirmar(
            $this->nextState ? 'Activar destino' : 'Desactivar destino',
            $registro->nombre.($this->nextState ? ' volverá a estar habilitado.' : ' dejará de estar disponible para nuevas atenciones. Se conserva el historial.'),
            'confirmState',
            $this->nextState ? 'Activar destino' : 'Desactivar destino',
            peligro: ! $this->nextState,
        );
    }

    public function confirmState(GestionDestinos $gestion): void
    {
        abort_if($this->stateId === null, 404);
        try {
            $gestion->cambiarEstado($this->actor(), $this->stateId, $this->nextState);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->notificar($exception->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->notificar($this->nextState ? 'Destino activado.' : 'Destino desactivado.');
        $this->reset('stateId');
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    public function render(): View
    {
        Gate::authorize('configuracion.restaurante');
        $busqueda = trim(mb_substr($this->search, 0, 100));
        $consulta = AreaPreparacion::query()->withCount('productos')->withCount('impresoras')->orderBy('nombre')->orderBy('id');
        if ($busqueda !== '') {
            $consulta->where(fn (Builder $query) => $query->where('nombre', 'like', '%'.$busqueda.'%')->orWhere('codigo', 'like', '%'.$busqueda.'%'));
        }
        if (in_array($this->stateFilter, ['activo', 'inactivo'], true)) {
            $consulta->where('activo', $this->stateFilter === 'activo');
        }

        return view('livewire.configuracion.destinos', [
            'destinos' => $consulta->paginate(15),
        ]);
    }
}
