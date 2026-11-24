<?php

namespace App\Livewire\Configuracion;

use App\Concerns\ConAlertas;
use App\Models\MedioPago;
use App\Services\Configuracion\GestionCatalogosCobro;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Medios de pago')]
class MediosPago extends Component
{
    use ConAlertas;
    use WithPagination;

    public string $search = '';
    public string $stateFilter = '';

    #[Locked]
    public ?int $medioId = null;

    public bool $showForm = false;
    public string $codigo = '';
    public string $nombre = '';
    public bool $afectaEfectivo = false;
    public bool $requiereReferencia = false;
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
        $this->reset(
            'medioId',
            'codigo',
            'nombre',
            'afectaEfectivo',
            'requiereReferencia',
            'activo'
        );

        $this->showForm = true;
    }

    public function editRecord(int $id): void
    {
        Gate::authorize('configuracion.restaurante');

        $medio = MedioPago::findOrFail($id);

        $this->resetValidation();
        $this->medioId = $medio->id;
        $this->codigo = $medio->codigo;
        $this->nombre = $medio->nombre;
        $this->afectaEfectivo = $medio->afecta_efectivo;
        $this->requiereReferencia = $medio->requiere_referencia
            || in_array($medio->codigo, ['yape', 'plin'], true);
        $this->activo = $medio->activo;
        $this->showForm = true;
    }

    public function save(GestionCatalogosCobro $gestion): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof \App\Models\User, 403);

        $gestion->guardarMedio($actor, $this->medioId, [
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'afecta_efectivo' => $this->afectaEfectivo,
            'requiere_referencia' => $this->requiereReferencia,
            'activo' => $this->activo,
        ]);

        $mensaje = $this->medioId
            ? 'Medio de pago actualizado.'
            : 'Medio de pago registrado.';

        $this->showForm = false;
        $this->notificar($mensaje);
    }

    public function requestStateChange(int $id): void
    {
        Gate::authorize('configuracion.restaurante');

        $medio = MedioPago::findOrFail($id);

        $this->stateId = $medio->id;
        $this->nextState = ! $medio->activo;

        $this->confirmar(
            $this->nextState ? 'Activar medio de pago' : 'Desactivar medio de pago',
            $medio->nombre.' '.(
                $this->nextState
                    ? 'volverá a aparecer en las opciones de pago.'
                    : 'dejará de estar disponible para nuevos cobros y pagos.'
            ),
            'confirmState',
            $this->nextState ? 'Activar' : 'Desactivar',
            peligro: ! $this->nextState
        );
    }

    public function confirmState(GestionCatalogosCobro $gestion): void
    {
        abort_if($this->stateId === null, 404);

        $actor = Auth::user();
        abort_unless($actor instanceof \App\Models\User, 403);

        try {
            $gestion->cambiarEstadoMedio(
                $actor,
                $this->stateId,
                $this->nextState
            );
        } catch (ValidationException $error) {
            $this->setErrorBag($error->validator->errors());
            $this->notificar(
                $error->validator->errors()->first(),
                'error',
                toast: false
            );
            return;
        }

        $this->notificar(
            $this->nextState
                ? 'Medio de pago activado.'
                : 'Medio de pago desactivado.'
        );

        $this->stateId = null;
    }

    public function render(): View
    {
        Gate::authorize('configuracion.restaurante');

        $consulta = MedioPago::query()->orderBy('id');

        $buscar = trim($this->search);

        if ($buscar !== '') {
            $consulta->where(fn ($q) =>
                $q->where('nombre', 'like', '%'.$buscar.'%')
                  ->orWhere('codigo', 'like', '%'.$buscar.'%')
            );
        }

        if (in_array($this->stateFilter, ['activo', 'inactivo'], true)) {
            $consulta->where(
                'activo',
                $this->stateFilter === 'activo'
            );
        }

        return view('livewire.configuracion.medios-pago', [
            'medios' => $consulta->paginate(15),
        ]);
    }
}
