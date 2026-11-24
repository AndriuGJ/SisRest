<?php

namespace App\Livewire\Configuracion;

use App\Concerns\ConAlertas;
use App\Models\ConfiguracionAnulacion;
use App\Models\MotivoAnulacion;
use App\Models\User;
use App\Services\Pos\GestionMotivosAnulacion;
use App\Services\Pos\SeguridadAnulaciones as ServicioSeguridad;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Seguridad de anulaciones')]
class SeguridadAnulaciones extends Component
{
    use ConAlertas;

    public string $claveActual = '';
    public string $claveNueva = '';
    public string $claveConfirmacion = '';

    #[Locked]
    public ?int $motivoId = null;

    public bool $showMotivoForm = false;

    public string $codigo = '';
    public string $nombre = '';
    public string $orden = '10';
    public bool $activo = true;

    #[Locked]
    public ?int $stateId = null;

    #[Locked]
    public bool $nextState = false;

    public function mount(): void
    {
        Gate::authorize('configuracion.restaurante');
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function claveConfigurada(): bool
    {
        return ConfiguracionAnulacion::query()
            ->where('singleton', 1)
            ->whereNotNull('clave_hash')
            ->exists();
    }

    public function guardarClave(ServicioSeguridad $seguridad): void
    {
        Gate::authorize('configuracion.sistema');

        $this->validate([
            'claveNueva' => [
                'required',
                'regex:/^[0-9]{6,12}$/',
            ],
            'claveConfirmacion' => [
                'required',
                'same:claveNueva',
            ],
            'claveActual' => [
                $this->claveConfigurada() ? 'required' : 'nullable',
                'string',
            ],
        ]);

        try {
            $seguridad->guardarClave(
                $this->actor(),
                $this->claveNueva,
                $this->claveActual
            );
        } catch (ValidationException $e) {
            $this->addError(
                'claveActual',
                $e->validator->errors()->first()
            );

            return;
        }

        $this->reset(
            'claveActual',
            'claveNueva',
            'claveConfirmacion'
        );

        $this->notificar('Clave de anulaciones guardada correctamente.');
    }

    public function nuevoMotivo(): void
    {
        Gate::authorize('configuracion.restaurante');

        $this->resetValidation();
        $this->reset(
            'motivoId',
            'codigo',
            'nombre',
            'orden',
            'activo'
        );

        $this->showMotivoForm = true;
    }

    public function editarMotivo(int $id): void
    {
        Gate::authorize('configuracion.restaurante');

        $motivo = MotivoAnulacion::findOrFail($id);

        $this->resetValidation();
        $this->motivoId = $motivo->id;
        $this->codigo = $motivo->codigo;
        $this->nombre = $motivo->nombre;
        $this->orden = (string) $motivo->orden;
        $this->activo = $motivo->activo;
        $this->showMotivoForm = true;
    }

    public function guardarMotivo(
        GestionMotivosAnulacion $gestion
    ): void {
        $gestion->guardar(
            $this->actor(),
            $this->motivoId,
            [
                'codigo' => $this->codigo,
                'nombre' => $this->nombre,
                'orden' => $this->orden,
                'activo' => $this->activo,
            ]
        );

        $this->showMotivoForm = false;

        $this->notificar(
            $this->motivoId
                ? 'Motivo actualizado.'
                : 'Motivo registrado.'
        );
    }

    public function solicitarCambioEstado(int $id): void
    {
        Gate::authorize('configuracion.restaurante');

        $motivo = MotivoAnulacion::findOrFail($id);

        $this->stateId = $motivo->id;
        $this->nextState = ! $motivo->activo;

        $this->confirmar(
            $this->nextState
                ? 'Activar motivo'
                : 'Desactivar motivo',
            $motivo->nombre.'. '.(
                $this->nextState
                    ? 'Volverá a estar disponible.'
                    : 'Dejará de aparecer en nuevas anulaciones.'
            ),
            'confirmarCambioEstado',
            $this->nextState ? 'Activar' : 'Desactivar',
            peligro: ! $this->nextState
        );
    }

    public function confirmarCambioEstado(
        GestionMotivosAnulacion $gestion
    ): void {
        abort_if($this->stateId === null, 404);

        try {
            $gestion->cambiarEstado(
                $this->actor(),
                $this->stateId,
                $this->nextState
            );
        } catch (ValidationException $e) {
            $this->notificar(
                $e->validator->errors()->first(),
                'error',
                toast: false
            );

            return;
        }

        $this->notificar(
            $this->nextState
                ? 'Motivo activado.'
                : 'Motivo desactivado.'
        );

        $this->stateId = null;
    }

    public function render(): View
    {
        Gate::authorize('configuracion.restaurante');

        return view('livewire.configuracion.seguridad-anulaciones', [
            'claveConfigurada' => $this->claveConfigurada(),
            'motivos' => MotivoAnulacion::query()
                ->orderBy('orden')
                ->orderBy('nombre')
                ->get(),
        ]);
    }
}
