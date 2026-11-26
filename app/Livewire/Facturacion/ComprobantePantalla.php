<?php

namespace App\Livewire\Facturacion;

use App\Concerns\ConAlertas;
use App\Models\Comprobante;
use App\Models\User;
use App\Services\Facturacion\AccesoFacturacion;
use App\Services\Facturacion\EnviarComprobante;
use App\Services\Facturacion\FalloSunat;
use App\Services\Facturacion\XmlFiscal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Comprobante electrónico')]
class ComprobantePantalla extends Component
{
    use ConAlertas;

    #[Locked]
    public int $comprobanteId;

    #[Locked]
    public bool $envioConfirmado = false;

    #[Locked]
    public ?int $reprocesarEnvioId = null;

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function comprobante(): Comprobante
    {
        $c = Comprobante::with(['detalles', 'envios', 'cobro'])->findOrFail($this->comprobanteId);
        AccesoFacturacion::cobro($this->actor(), $c->cobro()->firstOrFail());

        return $c;
    }

    public function mount(Comprobante $comprobante): void
    {
        $this->comprobanteId = $comprobante->id;
        $this->comprobante();
        app(EnviarComprobante::class)->recuperarInterrumpido($this->actor(), $this->comprobanteId);
    }

    public function actualizar(): void
    {
        app(EnviarComprobante::class)->recuperarInterrumpido($this->actor(), $this->comprobanteId);
    }

    public function solicitarEnvio(): void
    {
        $c = $this->comprobante();
        AccesoFacturacion::usuario($this->actor(), 'comprobantes.emitir');
        $this->envioConfirmado = true;
        $this->confirmar('Enviar a SUNAT', mb_strtoupper(XmlFiscal::dato($c, 'entorno')).' · '.XmlFiscal::numero($c).'. '.(XmlFiscal::dato($c, 'tipo') === '03' ? 'Se informará esta boleta mediante un resumen y luego podrás consultar su ticket.' : 'Se enviará el XML firmado y se guardará la constancia de recepción.'), 'enviar', 'Enviar documento');
    }

    public function enviar(): void
    {
        abort_unless($this->envioConfirmado, 403);
        $this->envioConfirmado = false;
        app(EnviarComprobante::class)->enviar($this->actor(), $this->comprobanteId);
        $this->notificar('Revisa el estado de SUNAT y el historial de envío.', 'info', toast: false);
    }

    public function consultar(): void
    {
        app(EnviarComprobante::class)->consultar($this->actor(), $this->comprobanteId);
        $this->notificar('Consulta terminada. Revisa la respuesta registrada.', 'info');
    }

    public function solicitarReprocesamiento(int $envioId): void
    {
        $c = $this->comprobante();
        AccesoFacturacion::usuario($this->actor(), 'comprobantes.emitir');
        $c->envios()->findOrFail($envioId);
        $this->reprocesarEnvioId = $envioId;
        $this->confirmar('Reprocesar CDR almacenado', XmlFiscal::numero($c).' · Se verificará el SHA-256 y se registrará el resultado del CDR guardado. Esta operación es local: no envía ni consulta SUNAT y conserva el diagnóstico original.', 'reprocesarCdr', 'Reprocesar localmente');
    }

    public function reprocesarCdr(): void
    {
        abort_if($this->reprocesarEnvioId === null, 403);
        $envioId = $this->reprocesarEnvioId;
        $this->reprocesarEnvioId = null;
        try {
            $c = app(EnviarComprobante::class)->reprocesarCdr($this->actor(), $this->comprobanteId, $envioId);
            $this->notificar('CDR reprocesado localmente. Estado: '.$c->estado_sunat->value.'.', 'success', toast: false);
        } catch (FalloSunat $error) {
            $this->addError('facturacion', $error->getMessage());
        }
    }

    public function render(): View
    {
        return view('livewire.facturacion.comprobante', ['comprobante' => $this->comprobante()]);
    }
}
