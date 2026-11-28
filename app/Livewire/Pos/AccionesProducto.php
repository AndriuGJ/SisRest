<?php

namespace App\Livewire\Pos;

use App\Concerns\ConAlertas;
use App\Models\MotivoAnulacion;
use App\Models\MotivoBeneficio;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Pos\AccesoPos;
use App\Services\Pos\GestionAnulaciones;
use App\Services\Pos\GestionBeneficios;
use App\Services\Pos\SeguridadAnulaciones as ServicioSeguridad;
use App\Support\Alertas;
use App\Support\Cantidad;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class AccionesProducto extends Component
{
    use ConAlertas;

    #[Locked]
    public int $pedidoId;

    #[Locked]
    public int $detalleId = 0;

    #[Locked]
    public string $modo = '';

    public bool $showModal = false;

    public string $accion = 'descuento';

    public string $tipo = 'porcentaje';

    public string $valor = '';

    public string $motivoId = '';

    public string $motivo = '';

    public string $cantidad = '1';

    #[Locked]
    public int $cantidadMaxima = 0;

    public string $motivoAnulacionId = '';

    public bool $showConfirmCancel = false;

    #[Locked]
    public int $motivoAnulacionRevisadoId = 0;

    #[Locked]
    public string $resumenAnulacion = '';

    #[Locked]
    public string $clave = '';

    #[Locked]
    public string $huella = '';

    #[Locked]
    public string $cantidadRevisada = '';

    #[Locked]
    public string $motivoRevisado = '';

    /** @var array{accion:string,tipo:string,valor:string,motivoId:string,motivo:string}|array{} */
    #[Locked]
    public array $datosRevisados = [];

    public function mount(int $pedidoId): void
    {
        $this->pedidoId = $pedidoId;
        $this->pedido();
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return AccesoPos::usuario($actor);
    }

    private function pedido(): Pedido
    {
        $pedido = Pedido::findOrFail($this->pedidoId);
        AccesoPos::pedido($this->actor(), $pedido);

        return $pedido;
    }

    #[On('pos:beneficio')]
    public function openBenefit(int $detalleId): void
    {
        $actor = $this->actor();
        abort_unless($actor->can('descuentos.aplicar') || $actor->can('cortesias.aplicar'), 403);
        $this->pedido()->detalles()->whereNull('detalle_padre_id')->findOrFail($detalleId);
        $this->resetValidation();
        $this->detalleId = $detalleId;
        $this->modo = 'beneficio';
        $this->accion = $actor->can('descuentos.aplicar') ? 'descuento' : 'cortesia';
        $this->tipo = 'porcentaje';
        $this->valor = $this->motivoId = $this->motivo = '';
        $this->datosRevisados = [];
        $this->huella = '';
        $this->showModal = true;
    }

    #[On('pos:anulacion')]
    public function openCancel(int $detalleId): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.anular_enviados');
        $this->pedido()->detalles()->whereNull('detalle_padre_id')->findOrFail($detalleId);
        $this->resetValidation();
        $this->detalleId = $detalleId;
        $this->modo = 'anular';
        $this->cantidadMaxima = app(GestionAnulaciones::class)->maximoAnulable($this->actor(), $this->pedidoId, $detalleId);
        $this->motivoAnulacionId = '';
        $this->motivoAnulacionRevisadoId = 0;
        $this->motivoRevisado = '';
        $this->resumenAnulacion = '';
        $this->showConfirmCancel = false;
        $this->cantidad = '1';
        $this->motivo = '';
        $this->huella = '';
        $this->showModal = true;
    }

    public function review(
        GestionAnulaciones $anulaciones,
        GestionBeneficios $beneficios
    ): void {
        $this->resetValidation();

        $actor = $this->actor();

        if ($this->modo === 'anular') {
            $motivo = app(ServicioSeguridad::class)
                ->motivoActivo($this->motivoAnulacionId);

            $plan = $anulaciones->previsualizar(
                $actor,
                $this->pedidoId,
                $this->detalleId,
                $this->cantidad,
                $motivo->nombre
            );

            $this->cantidadRevisada = $this->cantidad;
            $this->motivoRevisado = $motivo->nombre;
            $this->motivoAnulacionRevisadoId = $motivo->id;

            $partes = [];

            foreach ($plan['hojas'] as $hoja) {
                $partes[] = $hoja['descripcion']
                    .': liberar '.Cantidad::mostrar($hoja['liberada'])
                    .', merma '.Cantidad::mostrar($hoja['merma']);
            }

            $this->resumenAnulacion =
                'Anular '.Cantidad::mostrar($plan['cantidad'])
                .' de '.$plan['descripcion'].'. '
                .implode('; ', $partes)
                .'. Lo registrado como merma no volverá a estar disponible.'
                .' No se devolverá dinero.';

            $this->huella = $plan['huella'];
            $this->clave = (string) Str::uuid();

            $this->showModal = false;
            $this->showConfirmCancel = true;

            return;
        }

        abort_unless($this->modo === 'beneficio', 403);

        $datos = [
            'accion' => $this->accion,
            'tipo' => $this->tipo,
            'valor' => $this->valor,
            'motivoId' => $this->motivoId,
            'motivo' => $this->motivo,
        ];

        $plan = $beneficios->previsualizar(
            $actor,
            $this->pedidoId,
            $this->detalleId,
            $datos
        );

        $this->datosRevisados = $datos;

        $texto = $this->accion === 'quitar'
            ? 'Retirar el beneficio de '.$plan['descripcion']
                .'. Se conservará su autorización anterior en el historial.'
            : 'Autorizar '.$this->accion.' para '.$plan['descripcion']
                .'. Beneficio total de esta línea: S/ '.$plan['importe']
                .'. Se repartirá entre sus cantidades al cobrar.'
                .' No cambia existencias.';

        $this->huella = $plan['huella'];
        $this->clave = (string) Str::uuid();
        $this->showModal = false;

        $this->confirmar(
            'Autorizar beneficio',
            $texto,
            'confirmOperation',
            'Autorizar'
        );
    }

    public function confirmOperation(
        GestionAnulaciones $anulaciones,
        GestionBeneficios $beneficios,
        #[\SensitiveParameter] string $claveAutorizacion = ''
    ): void {
        $this->resetValidation();
        $actor = $this->actor();

        if ($this->huella === '' || $this->clave === '') {
            $this->notificar(
                'Revisa la operación antes de confirmar.',
                'warning',
                toast: false
            );

            return;
        }

        try {
            if ($this->modo === 'anular') {
                abort_unless(
                    $this->motivoAnulacionRevisadoId > 0,
                    403
                );

                $anulaciones->anular(
                    $actor,
                    $this->pedidoId,
                    $this->detalleId,
                    $this->cantidadRevisada,
                    $this->motivoRevisado,
                    $this->clave,
                    $this->huella,
                    $this->motivoAnulacionRevisadoId,
                    $claveAutorizacion
                );
            } else {
                abort_unless(
                    $this->modo === 'beneficio'
                        && $this->datosRevisados !== [],
                    403
                );

                $beneficios->aplicar(
                    $actor,
                    $this->pedidoId,
                    $this->detalleId,
                    $this->clave,
                    $this->huella,
                    $this->datosRevisados
                );
            }
        } catch (ValidationException $error) {
            if ($this->modo === 'anular') {
                $this->addError('anulacion', $error->validator->errors()->first());

                return;
            }

            $this->notificar(
                $error->validator->errors()->first(),
                'error',
                toast: false
            );

            return;
        }

        $this->showConfirmCancel = false;

        Alertas::flash(
            $this->modo === 'anular'
                ? 'Anulación registrada.'
                : 'Beneficio autorizado.'
        );

        $this->redirectRoute(
            'pos.pedido',
            $this->pedidoId,
            navigate: true
        );
    }

    public function cancelarConfirmacionAnulacion(): void
    {
        $this->huella = '';
        $this->showConfirmCancel = false;
    }

    public function render(): View
    {
        $pedido = $this->pedido();

        return view('livewire.pos.acciones-producto', [
            'detalle' => $this->detalleId
                ? $pedido->detalles()->find($this->detalleId)
                : null,

            'motivos' => MotivoBeneficio::where('activo', true)
                ->orderBy('nombre')
                ->get(),

            'motivosAnulacion' => MotivoAnulacion::where('activo', true)
                ->orderBy('orden')
                ->orderBy('nombre')
                ->get(),
        ]);
    }

    public function disminuirCantidad(): void
    {
        $actual = max(1, (int) $this->cantidad);
        $this->cantidad = (string) max(1, $actual - 1);
    }

    public function aumentarCantidad(): void
    {
        $actual = max(1, (int) $this->cantidad);
        $this->cantidadMaxima = app(GestionAnulaciones::class)->maximoAnulable($this->actor(), $this->pedidoId, $this->detalleId);
        $this->cantidad = (string) min($this->cantidadMaxima, $actual + 1);
    }
}
