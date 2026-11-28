<?php

namespace App\Livewire\Compras;

use App\Concerns\ConAlertas;
use App\Models\Compra;
use App\Models\MedioPago;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Compras\GestionCompras;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Detalle de compra')]
class CompraDetalle extends Component
{
    use ConAlertas;

    #[Locked]
    public int $compraId;

    #[Locked]
    public string $version = '';

    #[Locked]
    public string $clavePago = '';

    #[Locked]
    public string $accion = '';

    #[Locked]
    public string $motivoConfirmado = '';

    /** @var array{origen:string,medioId:string,turnoId:string,referencia:string,observaciones:string,monto:string}|array{} */
    #[Locked]
    public array $pagoConfirmado = [];

    public bool $showPayment = false;

    public bool $showCancel = false;

    public string $motivo = '';

    /** @var array{origen:string,medioId:string,turnoId:string,referencia:string,observaciones:string,monto:string} */
    public array $pago = ['origen' => 'empresa', 'medioId' => '', 'turnoId' => '', 'referencia' => '', 'observaciones' => '', 'monto' => ''];

    public function mount(Compra $compra): void
    {
        Gate::authorize('compras.ver');
        $this->compraId = $compra->id;
        $this->clavePago = (string) Str::uuid();
    }

    public function requestConfirm(): void
    {
        Gate::authorize('compras.confirmar');
        $compra = Compra::findOrFail($this->compraId);
        $this->resetValidation();
        $this->version = GestionCompras::huella($compra);
        $this->accion = 'confirmar';
        $this->pagoConfirmado = [];
        if ($compra->condicion_pago->value === 'contado') {
            Gate::authorize('pagos_proveedor.registrar');
            $this->pago['monto'] = $compra->total;
            $this->showPayment = true;
        } else {
            $this->confirmar('Confirmar compra', 'Se ingresarán las existencias recibidas. El total quedará pendiente de pago al proveedor.', 'execute', 'Confirmar recepción');
        }
    }

    public function requestPayment(): void
    {
        Gate::authorize('pagos_proveedor.registrar');
        $compra = Compra::findOrFail($this->compraId);
        abort_unless($compra->estado->value === 'confirmada', 403);
        $this->accion = 'pagar';
        $this->pago['monto'] = $compra->saldoPendiente();
        $this->resetValidation();
        $this->showPayment = true;
    }

    public function updatedPagoOrigen(): void
    {
        $this->pago['turnoId'] = '';
        $this->pago['medioId'] = '';
    }

    public function reviewPayment(): void
    {
        Gate::authorize('pagos_proveedor.registrar');
        abort_unless(in_array($this->accion, ['pagar', 'confirmar'], true), 403);
        $this->validate(['pago.origen' => ['required', 'in:empresa,dueno,caja'], 'pago.medioId' => ['required', 'integer'],
            'pago.monto' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/', 'numeric', 'gt:0'],
            'pago.turnoId' => ['nullable', 'required_if:pago.origen,caja', 'integer'],
            'pago.referencia' => ['nullable', 'string', 'max:150'], 'pago.observaciones' => ['nullable', 'string', 'max:2000']]);
        $this->pagoConfirmado = $this->pago;
        $this->showPayment = false;
        $this->confirmar($this->accion === 'confirmar' ? 'Recibir y pagar compra' : 'Registrar pago',
            'Se registrará el pago y su origen. El efectivo de caja solo cambia cuando el origen es una caja abierta.', 'execute', 'Confirmar');
    }

    public function execute(GestionCompras $gestion): void
    {
        $actor = $this->actor();
        abort_unless(in_array($this->accion, ['pagar', 'confirmar'], true), 403);
        $datos = $this->pagoConfirmado;
        try {
            if ($this->accion === 'confirmar') {
                $compra = Compra::findOrFail($this->compraId);
                abort_unless($compra->condicion_pago->value === 'credito' || $datos !== [], 403);
                $gestion->confirmar($actor, $this->compraId, $this->version, $this->clavePago, $datos ?: $this->pago);
            } else {
                abort_if($datos === [], 403);
                $gestion->registrarPago($actor, $this->compraId, $this->clavePago, $datos);
            }
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->accion = '';
        $this->pagoConfirmado = [];
        $this->clavePago = (string) Str::uuid();
        $this->notificar('Operación registrada. Compra, existencias y pagos actualizados.');
    }

    public function requestCancel(): void
    {
        Gate::authorize('compras.crear');
        $this->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);
        $this->version = GestionCompras::huella(Compra::findOrFail($this->compraId));
        $this->motivoConfirmado = $this->motivo;
        $this->showCancel = false;
        $this->confirmar('Anular borrador', 'Se conservará el registro sin generar movimientos ni pagos.', 'confirmCancel', 'Anular borrador', peligro: true);
    }

    public function confirmCancel(GestionCompras $gestion): void
    {
        try {
            $gestion->anular($this->actor(), $this->compraId, $this->version, $this->motivoConfirmado);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->notificar('Borrador anulado.');
    }

    private function actor(): User
    {
        Gate::authorize('compras.ver');
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    public function render(): View
    {
        $actor = $this->actor();
        $turnos = TurnoCaja::with('caja')->where('estado', 'abierta');
        if (! $actor->can('cajas.ver_todas')) {
            $turnos->where('usuario_id', $actor->id);
        }
        if (! $actor->can('cajas.registrar_egreso')) {
            $turnos->whereRaw('1 = 0');
        }

        return view('livewire.compras.compra-detalle', ['compra' => Compra::with(['proveedor', 'usuario', 'detalles', 'pagos.medioPago', 'pagos.usuario', 'pagos.turnoCaja.caja'])->findOrFail($this->compraId),
            'medios' => MedioPago::where('activo', true)->orderBy('id')->get(), 'turnos' => $turnos->get()]);
    }
}
