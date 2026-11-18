<?php

namespace App\Livewire\Configuracion;

use App\Concerns\ConAlertas;
use App\Models\Mesa;
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
#[Title('Mesas')]
class Mesas extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Url(as: 'salon', except: '')]
    public string $salonFilter = '';

    #[Locked]
    public ?int $mesaId = null;

    public bool $showForm = false;

    public string $salonId = '';

    public string $codigo = '';

    public string $capacidad = '';

    public bool $activo = true;

    #[Locked]
    public ?int $stateId = null;

    #[Locked]
    public bool $nextState = false;

    public function mount(): void
    {
        Gate::authorize('configuracion.restaurante');
    }

    public function updatedSalonFilter(): void
    {
        $this->resetPage();
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
        $this->reset('search', 'stateFilter', 'salonFilter');
        $this->resetPage();
    }

    public function newRecord(): void
    {
        Gate::authorize('configuracion.restaurante');
        $this->resetValidation();
        $this->reset('mesaId', 'salonId', 'codigo', 'capacidad', 'activo');
        $this->salonId = ctype_digit($this->salonFilter) ? $this->salonFilter : '';
        $this->showForm = true;
    }

    public function editRecord(int $id): void
    {
        Gate::authorize('configuracion.restaurante');
        $registro = Mesa::query()->findOrFail($id);
        $this->resetValidation();
        $this->mesaId = $registro->id;
        $this->salonId = (string) $registro->salon_id;
        $this->codigo = $registro->codigo;
        $this->capacidad = (string) ($registro->capacidad ?? '');
        $this->activo = $registro->activo;
        $this->showForm = true;
    }

    public function save(GestionRestaurante $gestion): void
    {
        $gestion->guardarMesa($this->actor(), $this->mesaId, [
            'salonId' => $this->salonId, 'codigo' => $this->codigo, 'capacidad' => $this->capacidad, 'activo' => $this->activo,
        ]);
        $this->showForm = false;
        $this->notificar($this->mesaId ? 'Mesa actualizada.' : 'Mesa registrada.');
    }

    public function requestStateChange(int $id): void
    {
        Gate::authorize('configuracion.restaurante');
        $registro = Mesa::query()->findOrFail($id);
        $this->resetValidation();
        $this->stateId = $registro->id;
        $this->nextState = ! $registro->activo;
        $this->confirmar(
            $this->nextState ? 'Activar mesa' : 'Desactivar mesa',
            $registro->codigo.($this->nextState ? ' volverá a estar habilitada.' : ' dejará de estar disponible para nuevas atenciones. Se conserva el historial.'),
            'confirmState',
            $this->nextState ? 'Activar mesa' : 'Desactivar mesa',
            peligro: ! $this->nextState,
        );
    }

    public function confirmState(GestionRestaurante $gestion): void
    {
        abort_if($this->stateId === null, 404);
        try {
            $gestion->cambiarEstadoMesa($this->actor(), $this->stateId, $this->nextState);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->notificar($exception->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->notificar($this->nextState ? 'Mesa activada.' : 'Mesa desactivada.');
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
        $consulta = Mesa::query()->with('salon')->withExists('ocupacion')
            ->withExists(['pedidos as pedido_pendiente_exists' => fn (Builder $query) => $query->whereNotIn('estado', ['cerrado', 'anulado'])])
            ->orderBy('salon_id')->orderBy('codigo')->orderBy('id');
        if ($busqueda !== '') {
            $consulta->where('codigo', 'like', '%'.$busqueda.'%');
        }
        if (ctype_digit($this->salonFilter)) {
            $consulta->where('salon_id', $this->salonFilter);
        }
        if (in_array($this->stateFilter, ['activo', 'inactivo'], true)) {
            $consulta->where('activo', $this->stateFilter === 'activo');
        }

        return view('livewire.configuracion.mesas', [
            'mesas' => $consulta->paginate(15),
            'salones' => Salon::query()->orderBy('orden')->orderBy('nombre')->get(),
        ]);
    }
}
