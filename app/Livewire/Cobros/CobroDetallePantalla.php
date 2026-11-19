<?php

namespace App\Livewire\Cobros;

use App\Models\Cobro;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Pos\AccesoPos;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Detalle de cobro')]
class CobroDetallePantalla extends Component
{
    #[Locked]
    public int $cobroId;

    public function mount(Cobro $cobro): void
    {
        $this->cobroId = $cobro->id;
        $this->cobro();
    }

    private function cobro(): Cobro
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $actual = AccesoPos::usuario($actor);
        Gate::forUser($actual)->authorize('cobros.crear');
        $cobro = Cobro::with(['pedido', 'turnoCaja.caja', 'usuario', 'detalles', 'pagos.medioPago'])->findOrFail($this->cobroId);
        AccesoPos::pedido($actual, $cobro->pedido);
        AccesoCajas::turno($actual, $cobro->turnoCaja);

        return $cobro;
    }

    public function render(): View
    {
        return view('livewire.cobros.detalle', ['cobro' => $this->cobro()]);
    }
}
