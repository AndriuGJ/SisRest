<?php

namespace App\Livewire\Cobros;

use App\Concerns\ConAlertas;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\MedioPago;
use App\Models\MotivoBeneficio;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Cobros\CalculoBeneficios;
use App\Services\Cobros\EstadoCuenta;
use App\Services\Cobros\GestionCobros;
use App\Services\Compras\ImportesCompra;
use App\Services\Pos\AccesoPos;
use App\Support\Alertas;
use App\Support\Cantidad;
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
#[Title('Cobrar y dividir cuenta')]
class CobroFormulario extends Component
{
    use ConAlertas;

    #[Locked]
    public int $pedidoId;

    public string $turnoId = '';

    public string $clienteId = '';

    public string $clienteSearch = '';

    public string $grupoPagador = '';

    public bool $incluirEnvio = false;

    public string $descuentoTipo = '';

    public string $descuentoValor = '0';

    public string $motivoId = '';

    public string $motivoDetalle = '';

    /** @var array<int,string> */
    public array $cantidades = [];

    /** @var array<string,array{medioId:string,monto:string,entregado:string,referencia:string}> */
    public array $pagos = [];

    #[Locked]
    public string $clave = '';

    /** @var array<string,mixed> */
    #[Locked]
    public array $revision = [];

    /** @var array{clienteId:string,grupoPagador:string,incluirEnvio:bool,descuentoTipo:string,descuentoValor:string,motivoId:string,motivoDetalle:string}|array{} */
    #[Locked]
    public array $datosRevisados = [];

    /** @var array<string,array{detalleId:string,cantidad:string}> */
    #[Locked]
    public array $seleccionRevisada = [];

    /** @var array<string,array{medioId:string,monto:string,entregado:string,referencia:string}> */
    #[Locked]
    public array $pagosRevisados = [];

    #[Locked]
    public int $turnoRevisado = 0;

    #[Locked]
    public string $huellaRevisada = '';

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $actual = AccesoPos::usuario($actor);
        Gate::forUser($actual)->authorize('cobros.crear');
        AccesoCajas::usuario($actual);

        return $actual;
    }

    private function pedido(): Pedido
    {
        $pedido = Pedido::findOrFail($this->pedidoId);
        AccesoPos::pedido($this->actor(), $pedido);

        return $pedido;
    }

    public function mount(Pedido $pedido): void
    {
        $this->pedidoId = $pedido->id;
        $this->pedido();
        $this->clienteId = (string) ($pedido->cliente_id ?? '');
        $this->turnoId = (string) (AccesoCajas::consulta($this->actor())->where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->orderBy('id')->value('id') ?? '');
        $this->incluirEnvio = bccomp(EstadoCuenta::envioPendiente($pedido), '0', 2) > 0;
        $this->selectPending();
        $this->addPayment();
    }

    public function selectPending(): void
    {
        $this->cantidades = [];
        foreach ($this->pedido()->detalles()->whereNull('detalle_padre_id')->where('estado', 'confirmado')->get() as $detalle) {
            $pendiente = bcsub(bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4), $detalle->cantidad_cobrada, 4);
            if (bccomp($pendiente, '0', 4) > 0) {
                $this->cantidades[$detalle->id] = Cantidad::mostrar($pendiente);
            }
        }
    }

    public function clearSelection(): void
    {
        $this->pedido();
        $this->cantidades = [];
        $this->incluirEnvio = false;
    }

    public function addPayment(): void
    {
        $this->pedido();
        if (count($this->pagos) < 10) {
            $this->pagos['p-'.Str::uuid()] = ['medioId' => '', 'monto' => '', 'entregado' => '', 'referencia' => ''];
        }
    }

    public function removePayment(string $clave): void
    {
        $this->pedido();
        unset($this->pagos[$clave]);
    }

    public function updated(string $campo): void
    {
        if ($campo === 'descuentoTipo' && $this->descuentoTipo === '') {
            $this->descuentoValor = '0';
            $this->motivoId = $this->motivoDetalle = '';
        }
        if (preg_match('/^pagos\.([^.]+)\.medioId$/', $campo, $partes) && isset($this->pagosParaVista($this->pagos)[$partes[1]])) {
            $this->pagos[$partes[1]]['entregado'] = '';
        }
    }

    public function review(GestionCobros $gestion): void
    {
        $this->resetValidation();
        $this->validate(['turnoId' => ['required', 'integer'], 'cantidades' => ['array', 'max:1000'], 'cantidades.*' => ['string', 'max:20']]);
        $seleccion = [];
        foreach ($this->cantidades as $id => $cantidad) {
            if ($cantidad !== '' && $cantidad !== '0') {
                $seleccion['d-'.$id] = ['detalleId' => (string) $id, 'cantidad' => $cantidad];
            }
        }
        $datos = ['clienteId' => $this->clienteId, 'grupoPagador' => $this->grupoPagador, 'incluirEnvio' => $this->incluirEnvio, 'descuentoTipo' => $this->descuentoTipo, 'descuentoValor' => $this->descuentoValor, 'motivoId' => $this->motivoId, 'motivoDetalle' => $this->motivoDetalle];
        $plan = $gestion->previsualizar($this->actor(), $this->pedidoId, (int) $this->turnoId, $datos, $seleccion, $this->pagos);
        $this->revision = $plan;
        $this->clave = (string) Str::uuid();
        $this->huellaRevisada = $plan['huella'];
        $this->datosRevisados = $datos;
        $this->seleccionRevisada = $seleccion;
        $this->pagosRevisados = $this->pagos;
        $this->turnoRevisado = (int) $this->turnoId;
        $this->confirmar('Registrar cobro', 'Total: S/ '.$plan['total'].'. Efectivo aplicado: S/ '.$plan['efectivo'].'. Digital: S/ '.$plan['digital'].'. Vuelto: S/ '.$plan['vuelto'].'. Solo se liquidarán los productos y el envío revisados.', 'confirmCharge', 'Cobrar S/ '.$plan['total']);
    }

    public function confirmCharge(GestionCobros $gestion): void
    {
        $actor = $this->actor();
        if ($this->datosRevisados === [] || $this->huellaRevisada === '' || $this->clave === '' || $this->turnoRevisado <= 0) {
            $this->notificar('Vuelve a revisar el cobro antes de confirmar.', 'warning', toast: false);

            return;
        }
        try {
            $cobro = $gestion->cobrar($actor, $this->pedidoId, $this->turnoRevisado, $this->clave, $this->huellaRevisada, $this->datosRevisados, $this->seleccionRevisada, $this->pagosRevisados);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        Alertas::flash('Cobro registrado.');
        $this->redirectRoute('pos.cobro', $cobro, navigate: true);
    }

    /** @return numeric-string */
    private function cantidadParaVista(mixed $valor): string
    {
        return is_string($valor) && preg_match('/^[1-9][0-9]{0,3}$/', $valor) && is_numeric($valor) ? bcadd($valor, '0', 4) : '0';
    }

    /** @return array<string,array{medioId:string,monto:string,entregado:string,referencia:string}> */
    private function pagosParaVista(mixed $entrada): array
    {
        if (! is_array($entrada)) {
            return [];
        }
        $resultado = [];
        foreach (array_slice($entrada, 0, 10, true) as $clave => $fila) {
            if (! is_string($clave) || ! is_array($fila)) {
                continue;
            }
            foreach (['medioId', 'monto', 'entregado', 'referencia'] as $campo) {
                if (! isset($fila[$campo]) || ! is_string($fila[$campo])) {
                    continue 2;
                }
            }
            $resultado[$clave] = ['medioId' => $fila['medioId'], 'monto' => $fila['monto'], 'entregado' => $fila['entregado'], 'referencia' => $fila['referencia']];
        }

        return $resultado;
    }

    public function render(): View
    {
        $actor = $this->actor();
        $pedido = $this->pedido()->load('detalles');
        $lineas = $pedido->detalles->whereNull('detalle_padre_id')->where('estado.value', 'confirmado');
        $subtotal = $descuentoProductos = $descuentoGeneral = '0.00';
        $lineasImportes = [];
        $puedeGeneral = true;
        $cantidadesVista = [];
        foreach ($lineas as $detalle) {
            $valor = $this->cantidades[$detalle->id] ?? '';
            $cantidad = $this->cantidadParaVista($valor);
            $cantidadesVista[$detalle->id] = $cantidad;
            $bruto = bcmul($cantidad, $detalle->precio_unitario, 2);
            $subtotal = bcadd($subtotal, $bruto, 2);
            $beneficio = '0.00';
            if (bccomp($cantidad, '0', 4) > 0) {
                $puedeGeneral = $puedeGeneral && ! ($detalle->es_cortesia || $detalle->descuento_tipo !== null || bccomp($detalle->descuento_importe, '0', 2) !== 0);
                try {
                    $beneficio = CalculoBeneficios::paraCantidad($detalle, $cantidad);
                } catch (ValidationException) { /* Revisar valida la selección completa. */
                }
            }
            $descuentoProductos = bcadd($descuentoProductos, $beneficio, 2);
            $lineasImportes[$detalle->id] = ['bruto' => $bruto, 'beneficio' => $beneficio, 'total' => bcsub($bruto, $beneficio, 2)];
        }
        $envioPendiente = EstadoCuenta::envioPendiente($pedido);
        if ($this->descuentoTipo !== '' && $puedeGeneral) {
            try {
                $descuentoGeneral = CalculoBeneficios::presupuesto($subtotal, $this->descuentoTipo, $this->descuentoValor);
            } catch (ValidationException) { /* La revisión muestra el error. */
            }
        }
        $total = bcadd(bcsub(bcsub($subtotal, $descuentoProductos, 2), $descuentoGeneral, 2), $this->incluirEnvio ? $envioPendiente : '0', 2);
        $clientes = collect();
        if ($actor->can('clientes.ver')) {
            $query = Cliente::where('activo', true)->orderBy('nombre');
            if (trim($this->clienteSearch) !== '') {
                $buscar = '%'.mb_substr(trim($this->clienteSearch), 0, 100).'%';
                $query->where(fn ($q) => $q->where('nombre', 'like', $buscar)->orWhere('numero_documento', 'like', $buscar));
            }
            $clientes = $query->limit(30)->get();
            if (ctype_digit($this->clienteId) && ! $clientes->contains('id', (int) $this->clienteId)) {
                $actual = Cliente::find($this->clienteId);
                if ($actual && ($actual->activo || $actual->id === $pedido->cliente_id)) {
                    $clientes->push($actual);
                }
            }
        }
        $medios = MedioPago::where('activo', true)->orderBy('id')->get();
        $pagosVista = [];
        $aplicado = $vuelto = $efectivo = '0.00';
        foreach ($this->pagosParaVista($this->pagos) as $clave => $fila) {
            $pagosVista[$clave] = $fila;
            try {
                $monto = ImportesCompra::importe($fila['monto'], 'pagos');
                $aplicado = bcadd($aplicado, $monto, 2);
                $medio = $medios->firstWhere('id', (int) $fila['medioId']);
                if ($medio?->afecta_efectivo) {
                    $efectivo = bcadd($efectivo, $monto, 2);
                    $recibido = $fila['entregado'] !== '' ? ImportesCompra::importe($fila['entregado'], 'pagos') : $monto;
                    if (bccomp($recibido, $monto, 2) >= 0) {
                        $vuelto = bcadd($vuelto, bcsub($recibido, $monto, 2), 2);
                    }
                }
            } catch (ValidationException) { /* La validación completa se realiza al revisar. */
            }
        }
        $turnosQuery = AccesoCajas::consulta($actor);
        $historial = Cobro::where('pedido_id', $pedido->id)->whereIn('turno_caja_id', (clone $turnosQuery)->select('id'))->latest('id')->get();

        return view('livewire.cobros.formulario', ['pedido' => $pedido, 'lineas' => $lineas, 'cantidadesVista' => $cantidadesVista, 'subtotal' => $subtotal, 'total' => $total,
            'envioPendiente' => $envioPendiente, 'descuentoProductos' => $descuentoProductos, 'descuentoGeneral' => $descuentoGeneral, 'puedeGeneral' => $puedeGeneral, 'lineasImportes' => $lineasImportes,
            'motivosBeneficio' => MotivoBeneficio::where('activo', true)->whereIn('tipo', ['descuento', 'ambos'])->orderBy('nombre')->get(), 'medios' => $medios, 'clientes' => $clientes, 'pagosVista' => $pagosVista, 'aplicado' => $aplicado, 'efectivo' => $efectivo, 'vuelto' => $vuelto,
            'turnos' => $turnosQuery->with('caja')->where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->get(), 'historial' => $historial]);
    }
}
