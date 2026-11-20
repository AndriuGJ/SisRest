<?php

namespace App\Livewire\Inventario;

use App\Concerns\ConAlertas;
use App\Models\Preparacion;
use App\Models\User;
use App\Services\Inventario\GestionPreparaciones;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Detalle de preparación')]
class PreparacionDetalle extends Component
{
    use ConAlertas;

    #[Locked]
    public int $preparacionId;

    #[Locked]
    public string $versionConfirmacion = '';

    #[Locked]
    public string $motivoConfirmado = '';

    public bool $showCancel = false;

    public string $motivo = '';

    public function mount(Preparacion $preparacion): void
    {
        Gate::authorize('preparaciones.ver');
        $this->preparacionId = $preparacion->id;
    }

    public function requestConfirm(): void
    {
        Gate::authorize('preparaciones.confirmar');
        $preparacion = Preparacion::query()->findOrFail($this->preparacionId);
        $this->versionConfirmacion = GestionPreparaciones::huellaBorrador($preparacion);
        $this->confirmar('Confirmar preparación', 'Se descontarán los ingredientes realmente utilizados y se ingresará la cantidad producida. Esta operación no se podrá editar después.', 'confirmProduction', 'Confirmar producción');
    }

    public function confirmProduction(GestionPreparaciones $gestion): void
    {
        try {
            $gestion->confirmar($this->actor(), $this->preparacionId, $this->versionConfirmacion);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->notificar('Preparación confirmada. Las existencias ya están actualizadas.');
    }

    public function requestCancel(): void
    {
        Gate::authorize('preparaciones.crear');
        $this->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);
        $preparacion = Preparacion::query()->findOrFail($this->preparacionId);
        $this->versionConfirmacion = GestionPreparaciones::huellaBorrador($preparacion);
        $this->motivoConfirmado = $this->motivo;
        $this->showCancel = false;
        $this->confirmar('Anular borrador', 'El borrador se conservará con su motivo. No se modificarán existencias.', 'confirmCancel', 'Anular borrador', peligro: true);
    }

    public function confirmCancel(GestionPreparaciones $gestion): void
    {
        try {
            $gestion->anular($this->actor(), $this->preparacionId, $this->motivoConfirmado, $this->versionConfirmacion);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->notificar('Borrador anulado. Las existencias se conservaron.');
    }

    private function actor(): User
    {
        Gate::authorize('preparaciones.ver');
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    public function render(): View
    {
        Gate::authorize('preparaciones.ver');

        return view('livewire.inventario.preparacion-detalle', ['preparacion' => Preparacion::query()
            ->with(['producto', 'usuario', 'confirmadaPor', 'detalles.articulo.unidadMedida'])->findOrFail($this->preparacionId)]);
    }
}
