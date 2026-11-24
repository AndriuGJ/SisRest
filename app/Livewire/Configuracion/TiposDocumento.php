<?php

namespace App\Livewire\Configuracion;

use App\Concerns\ConAlertas;
use App\Models\TipoComprobante;
use App\Models\User;
use App\Services\Configuracion\GestionCatalogosCobro;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Tipos de documento')]
class TiposDocumento extends Component
{
    use ConAlertas;

    #[Locked]
    public ?int $stateId = null;

    #[Locked]
    public bool $nextState = false;

    public function mount(): void
    {
        Gate::authorize('configuracion.restaurante');
    }

    public function requestStateChange(int $id): void
    {
        Gate::authorize('configuracion.restaurante');

        $tipo = TipoComprobante::query()
            ->whereIn('codigo', ['01', '03'])
            ->findOrFail($id);

        $this->stateId = $tipo->id;
        $this->nextState = ! $tipo->disponible_venta;

        $this->confirmar(
            $this->nextState ? 'Habilitar documento' : 'Deshabilitar documento',
            $tipo->nombre.' '.(
                $this->nextState
                    ? 'volverá a estar disponible para nuevas emisiones.'
                    : 'dejará de aparecer como opción para nuevos cobros.'
            ),
            'confirmState',
            $this->nextState ? 'Habilitar' : 'Deshabilitar',
            peligro: ! $this->nextState
        );
    }

    public function confirmState(GestionCatalogosCobro $gestion): void
    {
        abort_if($this->stateId === null, 404);

        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        $gestion->cambiarEstadoDocumento(
            $actor,
            $this->stateId,
            $this->nextState
        );

        $this->notificar(
            $this->nextState
                ? 'Documento habilitado.'
                : 'Documento deshabilitado.'
        );

        $this->stateId = null;
    }

    public function render(): View
    {
        Gate::authorize('configuracion.restaurante');

        return view('livewire.configuracion.tipos-documento', [
            'tipos' => TipoComprobante::query()
                ->whereIn('codigo', ['01', '03'])
                ->orderBy('codigo')
                ->get(),
        ]);
    }
}
