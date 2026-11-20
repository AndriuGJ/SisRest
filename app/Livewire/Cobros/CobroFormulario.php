<?php

namespace App\Livewire\Cobros;

use App\Concerns\ConAlertas;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\ConfiguracionFacturacion;
use App\Models\MedioPago;
use App\Models\MotivoBeneficio;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\SerieComprobante;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.cobro')]
#[Title('Cobrar y dividir cuenta')]
class CobroFormulario extends Component
{
    use ConAlertas;

    #[Locked]
    public int $pedidoId;

    public string $turnoId = '';

    public string $clienteId = '';

    public string $clienteSearch = '';

    #[Locked]
    public bool $pagoMixto = false;

    public string $documento = 'interno';

    #[Locked]
    public string $documentoRevisado = 'interno';

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
        $medio = MedioPago::where('activo', true)->where('afecta_efectivo', true)->first();
        if ($medio && bccomp($this->resumenSeleccion($pedido)['total'], '0', 2) > 0) {
            $this->usarMedio($medio->id);
        }
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

    public function mixedPayment(): void
    {
        $this->pedido();
        $actuales = $this->pagosParaVista($this->pagos);
        $filas = [];
        foreach (MedioPago::where('activo', true)->orderBy('id')->get() as $medio) {
            $existente = collect($actuales)->first(fn ($fila) => $fila['medioId'] === (string) $medio->id);
            $filas['m-'.$medio->id] = $existente ?? ['medioId' => (string) $medio->id, 'monto' => '', 'entregado' => '', 'referencia' => ''];
        }
        $this->pagos = $filas;
        $this->pagoMixto = true;
    }

    public function limpiarPago(string $clave): void
    {
        $this->pedido();
        $fila = $this->pagosParaVista($this->pagos)[$clave] ?? null;
        abort_unless($fila !== null, 422);
        $fila['monto'] = $fila['entregado'] = $fila['referencia'] = '';
        $this->pagos[$clave] = $fila;
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
        $filas = $this->pagosParaVista($this->pagos);
        if (preg_match('/^pagos\.([^.]+)\.medioId$/', $campo, $partes) && isset($filas[$partes[1]])) {
            $fila = $filas[$partes[1]];
            $fila['entregado'] = $fila['referencia'] = '';
            $this->pagos[$partes[1]] = $fila;
        }
        if ($this->pagoMixto && preg_match('/^pagos\.([^.]+)\.monto$/', $campo, $partes) && isset($filas[$partes[1]])) {
            $fila = $filas[$partes[1]];
            if ($fila['monto'] === '' || preg_match('/^0+(?:[.,]0{1,2})?$/', $fila['monto'])) {
                $fila['entregado'] = $fila['referencia'] = '';
                $this->pagos[$partes[1]] = $fila;
            }
        }
    }

    public function usarMedio(int $id): void
    {
        $pedido = $this->pedido();
        MedioPago::where('activo', true)->findOrFail($id);
        if ($this->pagoMixto || count($this->pagos) > 1) {
            $this->notificar('Elige los medios en cada fila del pago mixto.', 'info');

            return;
        }
        $clave = array_key_first($this->pagos) ?? 'p-'.Str::uuid();
        $this->pagos[$clave] = ['medioId' => (string) $id, 'monto' => $this->resumenSeleccion($pedido)['total'], 'entregado' => '', 'referencia' => ''];
    }

    public function completarPago(string $clave): void
    {
        $pedido = $this->pedido();
        $filas = $this->pagosParaVista($this->pagos);
        abort_unless(isset($filas[$clave]), 422);
        $restante = $this->resumenSeleccion($pedido)['total'];
        foreach ($filas as $otra => $fila) {
            if ($otra !== $clave && $fila['monto'] !== '') {
                $restante = bcsub($restante, ImportesCompra::importe($fila['monto'], 'pagos'), 2);
            }
        }
        $fila = $filas[$clave];
        $fila['monto'] = bccomp($restante, '0', 2) > 0 ? $restante : '0.00';
        $fila['entregado'] = '';
        $this->pagos[$clave] = $fila;
    }

    public function efectivoRapido(string $clave, string $valor): void
    {
        $this->pedido();
        $fila = $this->pagosParaVista($this->pagos)[$clave] ?? null;
        abort_unless($fila !== null && in_array($valor, ['exacto', '10', '20', '50', '100', '200'], true), 422);
        abort_unless(MedioPago::where('activo', true)->where('afecta_efectivo', true)->whereKey($fila['medioId'])->exists(), 422);
        $this->completarPago($clave);
        $this->pagos[$clave]['entregado'] = $valor === 'exacto' ? $this->pagos[$clave]['monto'] : $valor;
    }

    public function review(GestionCobros $gestion): void
    {
        $this->resetValidation();
        $this->validate(['documento' => ['required', 'in:interno,01,03'], 'turnoId' => ['required', 'integer'], 'cantidades' => ['array', 'max:1000'], 'cantidades.*' => ['string', 'max:20'], 'pagos' => ['array'], 'pagos.*' => ['array:medioId,monto,entregado,referencia'], 'pagos.*.medioId' => ['present', 'string', 'max:20'], 'pagos.*.monto' => ['present', 'string', 'max:20'], 'pagos.*.entregado' => ['present', 'string', 'max:20'], 'pagos.*.referencia' => ['present', 'string', 'max:150']]);
        $seleccion = [];
        foreach ($this->cantidades as $id => $cantidad) {
            if ($cantidad !== '' && $cantidad !== '0') {
                $seleccion['d-'.$id] = ['detalleId' => (string) $id, 'cantidad' => $cantidad];
            }
        }
        $datos = ['clienteId' => $this->clienteId, 'grupoPagador' => $this->grupoPagador, 'incluirEnvio' => $this->incluirEnvio, 'descuentoTipo' => $this->descuentoTipo, 'descuentoValor' => $this->descuentoValor, 'motivoId' => $this->motivoId, 'motivoDetalle' => $this->motivoDetalle];
        $pagos = [];
        foreach ($this->pagos as $clave => $fila) {
            // Los medios visibles sin uso no son pagos ni exigen referencia.
            if ($fila['entregado'] === '' && trim($fila['referencia']) === '' && ($fila['monto'] === '' || preg_match('/^0+(?:[.,]0{1,2})?$/', $fila['monto']))) {
                continue;
            }
            $pagos[$clave] = $fila;
        }
        $plan = $gestion->previsualizar($this->actor(), $this->pedidoId, (int) $this->turnoId, $datos, $seleccion, $pagos);
        if ($this->documento !== 'interno') {
            Gate::forUser($this->actor())->authorize('comprobantes.emitir');
            $config = ConfiguracionFacturacion::firstOrFail();
            if (! $config->habilitada || ! SerieComprobante::where('activo', true)->where('entorno', $config->entorno->value)->whereHas('tipoComprobante', fn ($q) => $q->where('activo', true)->where('codigo', $this->documento))->exists()) {
                throw ValidationException::withMessages(['documento' => 'Habilita la facturación y una serie del documento elegido antes de cobrar, o elige Nota de venta.']);
            }
            if ($this->documento === '01' && (! preg_match('/^[0-9]{11}$/', $plan['cliente']['documento'] ?? '') || trim($plan['cliente']['direccion'] ?? '') === '')) {
                throw ValidationException::withMessages(['clienteId' => 'Para factura selecciona un cliente con RUC y dirección.']);
            }
        }
        $this->documentoRevisado = $this->documento;
        $this->revision = $plan;
        $this->clave = (string) Str::uuid();
        $this->huellaRevisada = $plan['huella'];
        $this->datosRevisados = $datos;
        $this->seleccionRevisada = $seleccion;
        $this->pagosRevisados = $pagos;
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
        if ($this->documentoRevisado !== 'interno') {
            $this->redirectRoute('pos.emitir', ['cobro' => $cobro->id, 'documento' => $this->documentoRevisado], navigate: true);

            return;
        }
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
        foreach ($entrada as $clave => $fila) {
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

    /** @return array{lineas:Collection<int,PedidoDetalle>,cantidadesVista:array<int,numeric-string>,subtotal:numeric-string,total:numeric-string,envioPendiente:numeric-string,descuentoProductos:numeric-string,descuentoGeneral:numeric-string,puedeGeneral:bool,lineasImportes:array<int,array{bruto:numeric-string,beneficio:numeric-string,total:numeric-string}>} */
    private function resumenSeleccion(Pedido $pedido): array
    {
        $pedido->loadMissing('detalles');
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

        return compact('lineas', 'cantidadesVista', 'subtotal', 'total', 'envioPendiente', 'descuentoProductos', 'descuentoGeneral', 'puedeGeneral', 'lineasImportes');
    }

    public function render(): View
    {
        $actor = $this->actor();
        $pedido = $this->pedido()->load('detalles');
        $resumen = $this->resumenSeleccion($pedido);
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

        return view('livewire.cobros.formulario', $resumen + ['pedido' => $pedido,
            'motivosBeneficio' => MotivoBeneficio::where('activo', true)->whereIn('tipo', ['descuento', 'ambos'])->orderBy('nombre')->get(), 'medios' => $medios, 'clientes' => $clientes, 'pagosVista' => $pagosVista, 'aplicado' => $aplicado, 'efectivo' => $efectivo, 'vuelto' => $vuelto,
            'turnos' => $turnosQuery->with('caja')->where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->get(), 'historial' => $historial]);
    }
}
