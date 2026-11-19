<?php

namespace App\Livewire\Devoluciones;

use App\Concerns\ConAlertas;
use App\Models\Devolucion;
use App\Models\PedidoDetalle;
use App\Models\User;
use App\Services\Devoluciones\AccesoDevoluciones;
use App\Services\Devoluciones\ResolverProductosDevueltos;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Detalle de devolución')]
class DevolucionPantalla extends Component
{
    use ConAlertas;

    #[Locked]
    public int $devolucionId;

    #[Locked]
    public int $detalleId = 0;

    public string $cantidad = '1';

    public string $accion = 'mantener';

    public string $motivo = '';

    /** @var array<int,string> */
    public array $destinos = [];

    #[Locked]
    public string $clave = '';

    #[Locked]
    public string $huella = '';

    /** @var array{detalleId:int,cantidad:string,accion:string,destinos:array<int,string>,motivo:string}|array{} */
    #[Locked]
    public array $revisado = [];

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return AccesoDevoluciones::usuario($actor);
    }

    private function devolucion(): Devolucion
    {
        $devolucion = Devolucion::with(['cobro', 'turnoCaja.caja', 'detalles.detalleCobro', 'detalles.resoluciones.detalles', 'pagos.medioPago'])->findOrFail($this->devolucionId);
        AccesoDevoluciones::devolucion($this->actor(), $devolucion);

        return $devolucion;
    }

    public function mount(Devolucion $devolucion): void
    {
        $this->devolucionId = $devolucion->id;
        $this->devolucion();
    }

    public function selectProduct(int $id): void
    {
        $detalle = $this->devolucion()->detalles()->findOrFail($id);
        $this->resetValidation();
        $this->detalleId = $detalle->id;
        $this->cantidad = '1';
        $this->accion = 'mantener';
        $this->motivo = $this->clave = $this->huella = '';
        $this->revisado = $this->destinos = [];
        $raiz = $detalle->detalleCobro->detallePedido;
        foreach ($raiz->hijos()->exists() ? $raiz->hijos : collect([$raiz]) as $hoja) {
            $this->destinos[$hoja->id] = 'sin_reingreso';
        }
    }

    public function reviewProducts(ResolverProductosDevueltos $gestion): void
    {
        $devolucion = $this->devolucion();
        $devolucion->detalles()->findOrFail($this->detalleId);
        $this->resetValidation();
        $plan = $gestion->previsualizar($this->actor(), $this->detalleId, $this->cantidad, $this->accion, $this->destinos, $this->motivo);
        $this->revisado = ['detalleId' => $this->detalleId, 'cantidad' => $this->cantidad, 'accion' => $this->accion, 'destinos' => $this->destinos, 'motivo' => $this->motivo];
        $this->clave = (string) Str::uuid();
        $this->huella = $plan['huella'];
        $texto = match ($plan['accion']) {
            'mantener' => 'Se mantiene la entrega acordada, sin cambios de stock.',
            'cancelar_pendiente' => 'Se cancelará la entrega pendiente. Lo no iniciado libera reserva; lo iniciado se registra como merma.',
            default => 'Se registrará el retorno físico con el destino indicado para cada producto. Solo la reventa apta para venderse vuelve al stock.',
        };
        $this->confirmar('Resolver productos devueltos', 'Cantidad: '.$this->cantidad.'. '.$texto.' Esta acción no devuelve dinero otra vez.', 'confirmProducts', 'Confirmar productos', true);
    }

    public function confirmProducts(ResolverProductosDevueltos $gestion): void
    {
        $this->devolucion();
        if ($this->revisado === [] || $this->clave === '' || $this->huella === '') {
            $this->notificar('Revisa los productos antes de confirmar.', 'warning', toast: false);

            return;
        }
        $datos = $this->revisado;
        try {
            $gestion->resolver($this->actor(), $datos['detalleId'], $datos['cantidad'], $datos['accion'], $datos['destinos'], $datos['motivo'], $this->clave, $this->huella);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->detalleId = 0;
        $this->revisado = [];
        $this->clave = $this->huella = '';
        $this->notificar('Destino de los productos registrado.');
    }

    public function render(): View
    {
        $devolucion = $this->devolucion();
        $seleccionada = $this->detalleId ? $devolucion->detalles->firstWhere('id', $this->detalleId) : null;
        $hojas = collect();
        if ($seleccionada) {
            $raiz = PedidoDetalle::with(['hijos.producto.articulo', 'producto.articulo'])->findOrFail($seleccionada->detalleCobro->pedido_detalle_id);
            $hojas = $raiz->hijos->isEmpty() ? collect([$raiz]) : $raiz->hijos;
        }

        return view('livewire.devoluciones.detalle', ['devolucion' => $devolucion, 'seleccionada' => $seleccionada, 'hojas' => $hojas]);
    }
}
