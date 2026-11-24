<?php

namespace App\Livewire\Cobros;

use App\Concerns\ConAlertas;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ConfiguracionFacturacion;
use App\Models\MedioPago;
use App\Models\MotivoBeneficio;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProcesoCobro;
use App\Models\SerieComprobante;
use App\Models\TipoComprobante;
use App\Models\TipoDocumentoIdentidad;
use App\Models\TrabajoImpresion;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Clientes\GestionClientes;
use App\Services\Cobros\CalculoBeneficios;
use App\Services\Cobros\EstadoCuenta;
use App\Services\Cobros\GestionCobros;
use App\Services\Cobros\ProcesarVenta;
use App\Services\Compras\ImportesCompra;
use App\Services\Facturacion\ReglasFiscales;
use App\Services\Pos\AccesoPos;
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

/** @phpstan-import-type Fiscal from ProcesarVenta */
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

    public string $serieId = '';

    /** @var array<int|string,string> */
    public array $afectaciones = [];

    public bool $showChargeReview = false;

    public bool $showNewClient = false;

    public string $nuevoTipoId = '';

    public string $nuevoNumero = '';

    public string $nuevoNombre = '';

    public string $nuevoDireccion = '';

    #[Locked]
    public string $claveCliente = '';

    /** @var Fiscal|array{} */
    #[Locked]
    public array $fiscalRevisado = [];

    #[Locked]
    public string $formularioRevisado = '';

    #[Locked]
    public bool $puedeLiberar = false;

    #[Locked]
    public int $cobroResultado = 0;

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
        $resultado = request()->query('cobro');
        if (is_string($resultado) && ctype_digit($resultado)) {
            $this->cobroResultado = (int) $resultado;
            $this->cobroRegistrado();

            return;
        }
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

    public function updatedDocumento(): void
    {
        $config = ConfiguracionFacturacion::first();
        $this->serieId = (string) (SerieComprobante::where('activo', true)->where('entorno', $config?->entorno->value)->whereHas('tipoComprobante', fn ($q) => $q->where('activo', true)->where('disponible_venta', true)->where('codigo', $this->documento))->orderBy('id')->value('id') ?? '');
        $this->showChargeReview = false;
    }

    public function updatedTurnoId(): void
    {
        if ($this->showChargeReview) {
            $this->review(app(GestionCobros::class), app(ProcesarVenta::class));
        }
    }

    private function huellaFormulario(): string
    {
        return hash('sha256', serialize([$this->clienteId, $this->grupoPagador, $this->incluirEnvio, $this->descuentoTipo, $this->descuentoValor, $this->motivoId, $this->motivoDetalle, $this->cantidades, $this->pagos, $this->documento, $this->serieId, $this->afectaciones]));
    }

    public function abrirCliente(): void
    {
        Gate::forUser($this->actor())->authorize('clientes.crear');
        Gate::forUser($this->actor())->authorize('clientes.ver');
        $this->resetValidation();
        $this->nuevoTipoId = (string) (TipoDocumentoIdentidad::where('activo', true)->where('codigo', $this->documento === '01' ? '6' : '1')->value('id') ?? '');
        $this->nuevoNumero = $this->nuevoNombre = $this->nuevoDireccion = '';
        $this->claveCliente = (string) Str::uuid();
        $this->showNewClient = true;
    }

    public function guardarCliente(GestionClientes $gestion): void
    {
        $this->resetValidation();
        $this->validate(['nuevoTipoId' => ['required', 'integer'], 'nuevoNumero' => ['required', 'string', 'max:20'], 'nuevoNombre' => ['required', 'string', 'max:200'], 'nuevoDireccion' => ['string', 'max:250']]);
        $tipo = TipoDocumentoIdentidad::where('activo', true)->whereIn('codigo', ['1', '6'])->findOrFail($this->nuevoTipoId);
        if ($tipo->codigo === '6' && ! ReglasFiscales::ruc($this->nuevoNumero)) {
            throw ValidationException::withMessages(['nuevoNumero' => 'El RUC no es válido.']);
        }
        $cliente = $gestion->guardar($this->actor(), null, $this->claveCliente, ['tipo' => $tipo->codigo === '6' ? 'empresa' : 'persona', 'nombre' => $this->nuevoNombre, 'tipoId' => $this->nuevoTipoId, 'numero' => $this->nuevoNumero, 'direccion' => $this->nuevoDireccion, 'telefono' => '', 'correo' => '', 'activo' => true]);
        $this->clienteId = (string) $cliente->id;
        $this->clienteSearch = $cliente->numero_documento ?? $cliente->nombre;
        $this->showNewClient = false;
    }

    public function review(GestionCobros $gestion, ProcesarVenta $venta): void
    {
        $this->resetValidation();
        $this->validate(['afectaciones' => ['array'], 'afectaciones.*' => ['string', 'max:2'], 'documento' => ['required', 'in:interno,01,03'], 'turnoId' => ['required', 'integer'], 'cantidades' => ['array', 'max:1000'], 'cantidades.*' => ['string', 'max:20'], 'pagos' => ['array'], 'pagos.*' => ['array:medioId,monto,entregado,referencia'], 'pagos.*.medioId' => ['present', 'string', 'max:20'], 'pagos.*.monto' => ['present', 'string', 'max:20'], 'pagos.*.entregado' => ['present', 'string', 'max:20'], 'pagos.*.referencia' => ['present', 'string', 'max:150']]);
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
        if ($this->documento !== 'interno' && $this->serieId === '') {
            $this->updatedDocumento();
        }
        $this->fiscalRevisado = $venta->fiscal($this->actor(), $plan, $this->documento, $this->serieId, $this->afectaciones);
        $turno = AccesoCajas::consulta($this->actor())->findOrFail($this->turnoId);
        $this->showChargeReview = true;
        $venta->impresora($this->actor(), $turno->caja_id);
        $this->puedeLiberar = $venta->cubreCuenta($this->pedido(), $seleccion, $this->incluirEnvio);
        $this->formularioRevisado = $this->huellaFormulario();
        $this->documentoRevisado = $this->documento;
        $this->revision = $plan;
        $this->clave = (string) Str::uuid();
        $this->huellaRevisada = $plan['huella'];
        $this->datosRevisados = $datos;
        $this->seleccionRevisada = $seleccion;
        $this->pagosRevisados = $pagos;
        $this->turnoRevisado = (int) $this->turnoId;
        $this->showChargeReview = true;
    }

    public function confirmCharge(bool $liberar = false): void
    {
        if ($this->datosRevisados === [] || $this->fiscalRevisado === [] || $this->clave === '' || $this->turnoRevisado !== (int) $this->turnoId || ! hash_equals($this->formularioRevisado, $this->huellaFormulario())) {
            throw ValidationException::withMessages(['seguridad' => 'Revisa nuevamente el cobro antes de confirmar.']);
        }
        $this->showChargeReview = false;
        $venta = app(ProcesarVenta::class);
        $proceso = $venta->registrar($this->actor(), $this->pedidoId, $this->turnoRevisado, $this->clave, $this->huellaRevisada, $this->datosRevisados, $this->seleccionRevisada, $this->pagosRevisados, $this->fiscalRevisado, $liberar);
        $this->cobroResultado = $proceso->cobro_id;
        $venta->continuar($this->actor(), $proceso->id);
        $this->redirectRoute('pos.cobrar', ['pedido' => $this->pedidoId, 'cobro' => $proceso->cobro_id], navigate: true);
    }

    private function cobroRegistrado(): Cobro
    {
        $cobro = Cobro::with('pagos')->findOrFail($this->cobroResultado);
        abort_unless($cobro->pedido_id === $this->pedidoId, 404);
        AccesoPos::pedido($this->actor(), $cobro->pedido);
        AccesoCajas::turno($this->actor(), $cobro->turnoCaja);

        return $cobro;
    }

    public function reintentarProceso(ProcesarVenta $venta): void
    {
        $cobro = $this->cobroRegistrado();
        $proceso = ProcesoCobro::where('cobro_id', $cobro->id)->firstOrFail();
        $venta->continuar($this->actor(), $proceso->id);
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
        if ($this->cobroResultado > 0) {
            $cobro = $this->cobroRegistrado();
            $proceso = ProcesoCobro::where('cobro_id', $cobro->id)->first();

            return view('livewire.cobros.resultado', ['pedido' => $pedido, 'cobro' => $cobro, 'proceso' => $proceso, 'trabajo' => $proceso?->trabajo_impresion_id ? TrabajoImpresion::find($proceso->trabajo_impresion_id) : null, 'comprobante' => $proceso?->comprobante_id ? Comprobante::find($proceso->comprobante_id) : null, 'pagada' => EstadoCuenta::liquidada($pedido)]);
        }
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

        $config = ConfiguracionFacturacion::first();

        $tiposVenta = [];

        if ($actor->can('comprobantes.emitir')) {
            $codigosActivos = TipoComprobante::query()
                ->whereIn('codigo', ['01', '03'])
                ->where('activo', true)
                ->where('disponible_venta', true)
                ->pluck('codigo')
                ->all();

            foreach ([
                '03' => 'Boleta',
                '01' => 'Factura',
            ] as $codigo => $nombre) {
                if (in_array($codigo, $codigosActivos, true)) {
                    $tiposVenta[$codigo] = $nombre;
                }
            }
        }

        $tiposVenta['interno'] = 'Nota de venta';

        return view('livewire.cobros.formulario', $resumen + ['pedido' => $pedido,'tiposVenta' => $tiposVenta,
            'series' => SerieComprobante::with('tipoComprobante')->where('activo', true)->where('entorno', $config?->entorno->value)->whereHas('tipoComprobante', fn ($q) => $q->where('activo', true)->where('disponible_venta', true)->where('codigo', $this->documento))->orderBy('serie')->get(),
            'opcionesFiscales' => ReglasFiscales::AFECTACIONES,
            'tiposCliente' => TipoDocumentoIdentidad::where('activo', true)->whereIn('codigo', ['1', '6'])->get(),
            'motivosBeneficio' => MotivoBeneficio::where('activo', true)->whereIn('tipo', ['descuento', 'ambos'])->orderBy('nombre')->get(), 'medios' => $medios, 'clientes' => $clientes, 'pagosVista' => $pagosVista, 'aplicado' => $aplicado, 'efectivo' => $efectivo, 'vuelto' => $vuelto,
            'turnos' => $turnosQuery->with('caja.impresora')->where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->get(), 'historial' => $historial]);
    }
}
