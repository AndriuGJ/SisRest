<?php

namespace App\Livewire\Facturacion;

use App\Concerns\ConAlertas;
use App\Models\ResumenDiario;
use App\Models\User;
use App\Services\Facturacion\AccesoResumenes;
use App\Services\Facturacion\GestionResumenes;
use App\Services\Facturacion\ImportarResumenesHistoricos;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Resumen diario')]
class ResumenDiarioPantalla extends Component
{
    use ConAlertas;

    #[Locked]
    public int $resumenId;

    #[Locked]
    public bool $confirmado = false;

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    public function mount(ResumenDiario $resumen): void
    {
        $this->resumenId = $resumen->id;
        AccesoResumenes::autorizar($this->actor(), $resumen);
        app(GestionResumenes::class)->recuperarInterrumpido($this->actor(), $resumen->id);
        if ($resumen->envio_historico_id !== null) {
            app(ImportarResumenesHistoricos::class)->sincronizar($resumen->envio_historico_id);
        }
    }

    public function solicitarEnvio(): void
    {
        $r = ResumenDiario::findOrFail($this->resumenId);
        AccesoResumenes::autorizar($this->actor(), $r, 'comprobantes.emitir');
        $this->confirmado = true;
        $this->confirmar('Enviar resumen a SUNAT', strtoupper($r->entorno).' · '.$r->nombre.'. Se enviará el XML firmado una sola vez.', 'enviar', 'Enviar resumen');
    }

    public function enviar(): void
    {
        abort_unless($this->confirmado, 403);
        $this->confirmado = false;
        app(GestionResumenes::class)->enviar($this->actor(), $this->resumenId);
        $this->notificar('Operación terminada. Revisa el ticket y el historial.', 'info', toast: false);
    }

    public function consultar(): void
    {
        app(GestionResumenes::class)->consultar($this->actor(), $this->resumenId);
        $this->notificar('Consulta terminada. Solo un CDR verificable cambia el resultado fiscal.', 'info', toast: false);
    }

    public function render(): View
    {
        $resumen = ResumenDiario::with(['detalles.comprobante', 'eventos', 'envioHistorico'])->findOrFail($this->resumenId);
        AccesoResumenes::autorizar($this->actor(), $resumen);

        return view('livewire.facturacion.resumen-diario', compact('resumen'));
    }
}
