<?php

namespace App\Livewire\Cobros;

use App\Models\Cobro;
use App\Models\ConfiguracionFacturacion;
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
        $cobro = Cobro::with(['pedido', 'turnoCaja.caja', 'usuario', 'detalles', 'pagos.medioPago', 'comprobante' => fn ($q) => $q->where('entorno', ConfiguracionFacturacion::firstOrFail()->entorno->value)])->findOrFail($this->cobroId);
        AccesoPos::pedido($actual, $cobro->pedido);
        AccesoCajas::turno($actual, $cobro->turnoCaja);

        return $cobro;
    }

    public function render(): View
    {
        $cobro = $this->cobro();
        $devuelto = '0.00';
        foreach ($cobro->devoluciones()->where('estado', 'confirmada')->get() as $devolucion) {
            $devuelto = bcadd($devuelto, $devolucion->total, 2);
        }
        $historial = $cobro->devoluciones()->whereIn('turno_caja_id', AccesoCajas::consulta(Auth::user())->select('id'))->orderByDesc('id')->get();

        return view('livewire.cobros.detalle', ['cobro' => $cobro, 'devuelto' => $devuelto, 'historialDevoluciones' => $historial]);
    }
}
