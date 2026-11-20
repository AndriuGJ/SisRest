<?php

namespace App\Livewire\Configuracion;

use App\Concerns\ConAlertas;
use App\Models\Salon;
use App\Models\User;
use App\Services\Configuracion\GestionRestaurante;
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
#[Title('Salones')]
class Salones extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Locked]
    public ?int $salonId = null;

    public bool $showForm = false;

    public string $codigo = '';

    public string $nombre = '';

    public string $orden = '0';

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
        $this->reset('salonId', 'codigo', 'nombre', 'orden', 'activo');
        $this->showForm = true;
    }

    public function editRecord(int $id): void
    {
        Gate::authorize('configuracion.restaurante');
        $registro = Salon::query()->findOrFail($id);
        $this->resetValidation();
        $this->salonId = $registro->id;
        $this->codigo = $registro->codigo;
        $this->nombre = $registro->nombre;
        $this->orden = (string) $registro->orden;
        $this->activo = $registro->activo;
        $this->showForm = true;
    }

    public function save(GestionRestaurante $gestion): void
    {
        $gestion->guardarSalon($this->actor(), $this->salonId, [
            'codigo' => $this->codigo, 'nombre' => $this->nombre, 'orden' => $this->orden, 'activo' => $this->activo,
        ]);
        $this->showForm = false;
        $this->notificar($this->salonId ? 'Salón actualizado.' : 'Salón registrado.');
    }

    public function requestStateChange(int $id): void
    {
        Gate::authorize('configuracion.restaurante');
        $registro = Salon::query()->findOrFail($id);
        $this->resetValidation();
        $this->stateId = $registro->id;
        $this->nextState = ! $registro->activo;
        $this->confirmar(
            $this->nextState ? 'Activar salón' : 'Desactivar salón',
            $registro->nombre.($this->nextState ? ' volverá a estar habilitado.' : ' dejará de estar disponible para nuevas atenciones. Se conserva el historial.'),
            'confirmState',
            $this->nextState ? 'Activar salón' : 'Desactivar salón',
            peligro: ! $this->nextState,
        );
    }

    public function confirmState(GestionRestaurante $gestion): void
    {
        abort_if($this->stateId === null, 404);
        try {
            $gestion->cambiarEstadoSalon($this->actor(), $this->stateId, $this->nextState);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->notificar($exception->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->notificar($this->nextState ? 'Salón activado.' : 'Salón desactivado.');
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
        $consulta = Salon::query()->withCount('mesas')->withCount(['mesas as mesas_activas_count' => fn (Builder $query) => $query->where('activo', true)])->orderBy('orden')->orderBy('nombre')->orderBy('id');
        if ($busqueda !== '') {
            $consulta->where(fn (Builder $query) => $query->where('nombre', 'like', '%'.$busqueda.'%')->orWhere('codigo', 'like', '%'.$busqueda.'%'));
        }
        if (in_array($this->stateFilter, ['activo', 'inactivo'], true)) {
            $consulta->where('activo', $this->stateFilter === 'activo');
        }

        return view('livewire.configuracion.salones', [
            'salones' => $consulta->paginate(15),
        ]);
    }
}
