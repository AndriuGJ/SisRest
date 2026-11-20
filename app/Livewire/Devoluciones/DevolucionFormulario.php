<?php

namespace App\Livewire\Devoluciones;

use App\Concerns\ConAlertas;
use App\Models\Cobro;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Cobros\CalculoBeneficios;
use App\Services\Devoluciones\AccesoDevoluciones;
use App\Services\Devoluciones\GestionDevoluciones;
use App\Services\Devoluciones\SaldoDevolucion;
use App\Support\Alertas;
use App\Support\Cantidad;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @phpstan-import-type Seleccion from GestionDevoluciones
 * @phpstan-import-type Pagos from GestionDevoluciones
 */
#[Layout('layouts.app')]
#[Title('Registrar devolución')]
class DevolucionFormulario extends Component
{
    use ConAlertas;

    #[Locked]
    public int $cobroId;

    public string $turnoId = '';

    public string $motivo = '';

    public bool $incluirEnvio = false;

    /** @var array<int,string> */
    public array $cantidades = [];

    /** @var Pagos */
    public array $pagos = [];

    #[Locked]
    public string $clave = '';

    #[Locked]
    public string $huella = '';

    #[Locked]
    public int $turnoRevisado = 0;

    #[Locked]
    public string $motivoRevisado = '';

    #[Locked]
    public bool $envioRevisado = false;

    /** @var Seleccion */
    #[Locked]
    public array $seleccionRevisada = [];

    /** @var Pagos */
    #[Locked]
    public array $pagosRevisados = [];

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return AccesoDevoluciones::usuario($actor);
    }

    private function cobro(): Cobro
    {
        $cobro = Cobro::findOrFail($this->cobroId);
        AccesoDevoluciones::cobro($this->actor(), $cobro);

        return $cobro;
    }

    public function mount(Cobro $cobro): void
    {
        $this->cobroId = $cobro->id;
        $this->cobro();
        $this->turnoId = (string) (AccesoCajas::consulta($this->actor())->where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->orderBy('id')->value('id') ?? '');
        foreach (SaldoDevolucion::calcular($cobro)['pagos'] as $pago) {
            $this->pagos['p-'.$pago['id']] = ['pagoId' => (string) $pago['id'], 'monto' => '', 'referencia' => ''];
        }
    }

    public function selectRemaining(): void
    {
        $saldo = SaldoDevolucion::calcular($this->cobro());
        $this->cantidades = [];
        foreach ($saldo['lineas'] as $linea) {
            if (bccomp($linea['disponible'], '0', 4) > 0) {
                $this->cantidades[$linea['id']] = Cantidad::mostrar($linea['disponible']);
            }
        }
        $this->incluirEnvio = bccomp($saldo['envio'], '0', 2) > 0;
        foreach ($saldo['pagos'] as $pago) {
            $this->pagos['p-'.$pago['id']] = ['pagoId' => (string) $pago['id'], 'monto' => $pago['disponible'], 'referencia' => ''];
        }
    }

    public function review(GestionDevoluciones $gestion): void
    {
        $this->resetValidation();
        $this->validate(['turnoId' => ['required', 'integer'], 'cantidades' => ['array', 'max:500'], 'cantidades.*' => ['string', 'max:20']]);
        $seleccion = [];
        foreach ($this->cantidades as $id => $cantidad) {
            if ($cantidad !== '' && $cantidad !== '0') {
                $seleccion['d-'.$id] = ['detalleId' => (string) $id, 'cantidad' => $cantidad];
            }
        }
        $plan = $gestion->previsualizar($this->actor(), $this->cobroId, (int) $this->turnoId, $seleccion, $this->pagos, $this->incluirEnvio, $this->motivo);
        $this->seleccionRevisada = $seleccion;
        $this->pagosRevisados = $this->pagos;
        $this->turnoRevisado = (int) $this->turnoId;
        $this->motivoRevisado = $this->motivo;
        $this->envioRevisado = $this->incluirEnvio;
        $this->clave = (string) Str::uuid();
        $this->huella = $plan['huella'];
        $this->confirmar('Registrar devolución realizada', 'Total: S/ '.$plan['total'].'. Efectivo: S/ '.$plan['efectivo'].' desde '.$plan['caja'].'. Confirma que realizaste los reintegros indicados. El inventario se resolverá por separado.', 'confirmRefund', 'Registrar devolución', true);
    }

    public function confirmRefund(GestionDevoluciones $gestion): void
    {
        $actor = $this->actor();
        if ($this->clave === '' || $this->huella === '' || $this->turnoRevisado <= 0) {
            $this->notificar('Revisa la devolución antes de confirmar.', 'warning', toast: false);

            return;
        }
        try {
            $devolucion = $gestion->devolver($actor, $this->cobroId, $this->turnoRevisado, $this->seleccionRevisada, $this->pagosRevisados, $this->envioRevisado, $this->motivoRevisado, $this->clave, $this->huella);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        Alertas::flash('Devolución registrada. Revisa por separado el destino de los productos.');
        $this->redirectRoute('pos.devolucion', $devolucion, navigate: true);
    }

    private function cantidadVista(mixed $valor): string
    {
        return is_string($valor) ? $valor : '';
    }

    public function render(): View
    {
        $cobro = $this->cobro();
        $saldo = SaldoDevolucion::calcular($cobro);
        $total = $this->incluirEnvio ? $saldo['envio'] : '0.00';
        $importes = [];
        foreach ($saldo['lineas'] as $linea) {
            $q = $this->cantidadVista($this->cantidades[$linea['id']] ?? '');
            $importe = '0.00';
            if (preg_match('/^[1-9][0-9]{0,3}$/', $q) && is_numeric($q) && bccomp($q, $linea['disponible'], 4) <= 0) {
                $importe = CalculoBeneficios::repartir($linea['importe'], $q, $linea['disponible']);
            }
            $importes[$linea['id']] = $importe;
            $total = bcadd($total, $importe, 2);
        }

        return view('livewire.devoluciones.formulario', ['cobro' => $cobro, 'saldo' => $saldo, 'importes' => $importes, 'total' => $total,
            'historial' => $cobro->devoluciones()->where('estado', 'confirmada')->whereIn('turno_caja_id', AccesoCajas::consulta($this->actor())->select('id'))->latest('id')->get(),
            'turnos' => AccesoCajas::consulta($this->actor())->with('caja')->where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->get()]);
    }
}
