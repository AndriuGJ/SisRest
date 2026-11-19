<?php

namespace App\Livewire\Pos;

use App\Concerns\ConAlertas;
use App\Models\AnulacionPedido;
use App\Models\BeneficioPedido;
use App\Models\CategoriaProducto;
use App\Models\Cliente;
use App\Models\EnvioCocinaDetalle;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Cobros\CerrarAtencion;
use App\Services\Cobros\EstadoCuenta;
use App\Services\Inventario\CalculoProduccion;
use App\Services\Pos\AccesoPos;
use App\Services\Pos\CatalogoPos;
use App\Services\Pos\ConfirmarPedido;
use App\Services\Pos\GestionEntregas;
use App\Services\Pos\GestionPedidos;
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
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Pedido POS')]
class PedidoPantalla extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Locked]
    public int $pedidoId;

    #[Locked]
    public string $version = '';

    #[Locked]
    public string $versionDescarte = '';

    #[Locked]
    public string $motivoConfirmado = '';

    #[Locked]
    public string $formularioGuardado = '';

    #[Locked]
    public string $versionEnvio = '';

    #[Locked]
    public string $claveEnvio = '';

    #[Locked]
    public int $turnoEnvio = 0;

    #[Locked]
    public string $versionEntrega = '';

    #[Locked]
    public int $detalleEntrega = 0;

    #[Locked]
    public string $cantidadEntrega = '';

    /** @var array<int,string> */
    public array $cantidadesEntrega = [];

    #[Locked]
    public string $versionCierre = '';

    public string $turnoId = '';

    public string $clienteId = '';

    public string $comensales = '';

    public string $observaciones = '';

    public string $search = '';

    public string $categoriaId = '';

    public string $clienteSearch = '';

    public bool $showDiscard = false;

    public string $motivo = '';

    /** @var array<string,array{detalleId:string,productoId:string,cantidad:string,nota:string}> */
    public array $filas = [];

    public function mount(Pedido $pedido): void
    {
        $this->pedidoId = $pedido->id;
        $this->pedido();
        $this->version = GestionPedidos::huella($pedido);
        $this->clienteId = (string) ($pedido->cliente_id ?? '');
        $this->comensales = (string) ($pedido->comensales ?? '');
        $this->observaciones = $pedido->observaciones ?? '';
        $turno = TurnoCaja::where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->orderBy('id')->get();
        $elegido = $turno->firstWhere('id', $pedido->turno_apertura_id) ?? $turno->first();
        $this->turnoId = $elegido ? (string) $elegido->id : '';
        foreach ($pedido->detalles()->whereNull('detalle_padre_id')->where('estado', 'borrador')->get() as $detalle) {
            $this->filas['d-'.$detalle->id] = ['detalleId' => (string) $detalle->id, 'productoId' => (string) $detalle->producto_id, 'cantidad' => Cantidad::mostrar($detalle->cantidad), 'nota' => $detalle->nota ?? ''];
        }
        $this->formularioGuardado = $this->huellaFormulario();
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

    public function updated(string $campo): void
    {
        if (in_array($campo, ['search', 'categoriaId'], true)) {
            $this->resetPage();
        }
    }

    public function addProduct(int $id): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.editar');
        $this->pedido();
        $producto = app(CatalogoPos::class)->consulta()->findOrFail($id);
        if (bccomp(app(CatalogoPos::class)->disponible($producto), '0', 4) <= 0) {
            $this->notificar('Este producto no tiene disponibilidad.', 'warning');

            return;
        }
        if (count($this->filas) < 50) {
            $this->filas['n-'.Str::uuid()] = ['detalleId' => '', 'productoId' => (string) $id, 'cantidad' => '1', 'nota' => ''];
        }
    }

    public function removeRow(string $clave): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.editar');
        $this->pedido();
        unset($this->filas[$clave]);
    }

    public function save(GestionPedidos $gestion): void
    {
        $this->resetValidation();
        $this->validate(['turnoId' => ['required', 'integer']]);
        $gestion->guardar($this->actor(), $this->pedidoId, (int) $this->turnoId, $this->version, ['clienteId' => $this->clienteId, 'comensales' => $this->comensales, 'observaciones' => $this->observaciones], $this->filas);
        Alertas::flash('Borrador guardado. Las existencias se conservan.');
        $this->redirectRoute('pos.pedido', $this->pedidoId, navigate: true);
    }

    private function huellaFormulario(): string
    {
        return hash('sha256', json_encode([$this->clienteId, $this->comensales, $this->observaciones, $this->filas], JSON_THROW_ON_ERROR));
    }

    private function exigirGuardado(): void
    {
        if (! hash_equals($this->formularioGuardado, $this->huellaFormulario())) {
            throw ValidationException::withMessages(['seguridad' => 'Primero guarda los cambios del borrador para continuar.']);
        }
    }

    public function openPrinting(): void
    {
        $actor = $this->actor();
        abort_unless($actor->can('impresion.comandas') || $actor->can('impresion.precuentas'), 403);
        $this->pedido();
        $this->exigirGuardado();
        $this->redirectRoute('pos.imprimir-pedido', $this->pedidoId, navigate: true);
    }

    public function requestSend(): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.enviar');
        $this->exigirGuardado();
        $this->validate(['turnoId' => ['required', 'integer']]);
        $pedido = $this->pedido();
        $lineas = $pedido->detalles()->whereNull('detalle_padre_id')->where('estado', 'borrador')->count();
        if (! $lineas) {
            throw ValidationException::withMessages(['seguridad' => 'Guarda productos en borrador antes de confirmar.']);
        }
        $this->versionEnvio = $this->version;
        $this->claveEnvio = (string) Str::uuid();
        $this->turnoEnvio = (int) $this->turnoId;
        $this->confirmar('Confirmar productos', 'Se reservarán las existencias de '.$lineas.' líneas nuevas. Los productos con destino se enviarán a Cocina o Barra; los de entrega directa quedarán disponibles para entregar.', 'confirmSend', 'Confirmar y enviar');
    }

    public function confirmSend(ConfirmarPedido $servicio): void
    {
        try {
            $servicio->confirmar($this->actor(), $this->pedidoId, $this->turnoEnvio, $this->versionEnvio, $this->claveEnvio);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        Alertas::flash('Productos confirmados. Existencias reservadas y envíos registrados.');
        $this->redirectRoute('pos.pedido', $this->pedidoId, navigate: true);
    }

    public function requestDelivery(int $id): void
    {
        Gate::forUser($this->actor())->authorize('entregas.confirmar');
        $this->exigirGuardado();
        $this->validate(['cantidadesEntrega' => ['array'], 'cantidadesEntrega.*' => ['string', 'max:20']]);
        $detalle = $this->pedido()->detalles()->findOrFail($id);
        $this->detalleEntrega = $detalle->id;
        $this->cantidadEntrega = CalculoProduccion::cantidad($this->cantidadesEntrega[$id] ?? '1');
        $this->versionEntrega = $this->version;
        $this->confirmar('Confirmar entrega', 'Se entregará '.Cantidad::mostrar($this->cantidadEntrega).' de '.$detalle->descripcion.' y se descontará esa cantidad de la existencia reservada.', 'confirmDelivery', 'Entregar');
    }

    public function confirmDelivery(GestionEntregas $gestion): void
    {
        try {
            $gestion->entregar($this->actor(), $this->pedidoId, $this->detalleEntrega, $this->cantidadEntrega, $this->versionEntrega);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        Alertas::flash('Entrega registrada. Existencias descontadas.');
        $this->redirectRoute('pos.pedido', $this->pedidoId, navigate: true);
    }

    public function refreshPedido(): void
    {
        $this->pedido();
        if (! hash_equals($this->formularioGuardado, $this->huellaFormulario())) {
            $this->confirmar('Actualizar pedido', 'Los cambios del borrador que no guardaste se descartarán al actualizar.', 'confirmRefresh', 'Actualizar');

            return;
        }
        $this->confirmRefresh();
    }

    public function confirmRefresh(): void
    {
        $this->pedido();
        $this->redirectRoute('pos.pedido', $this->pedidoId, navigate: true);
    }

    public function requestClose(): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.editar');
        $this->exigirGuardado();
        $pedido = $this->pedido();
        if ($pedido->mesa_id) {
            Gate::forUser($this->actor())->authorize('mesas.liberar');
        }
        $this->versionCierre = EstadoCuenta::huella($pedido);
        $this->confirmar('Cerrar atención', 'Se comprobará que todos los productos estén pagados y entregados. Se cerrará la atención y se liberará únicamente su mesa.', 'confirmClose', 'Cerrar atención');
    }

    public function confirmClose(CerrarAtencion $gestion): void
    {
        try {
            $gestion->cerrar($this->actor(), $this->pedidoId, $this->versionCierre);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        Alertas::flash('Atención cerrada y mesa liberada.');
        $this->redirectRoute('pos.index', navigate: true);
    }

    public function openBenefit(int $id): void
    {
        $actor = $this->actor();
        abort_unless($actor->can('descuentos.aplicar') || $actor->can('cortesias.aplicar'), 403);
        $this->pedido();
        $this->exigirGuardado();
        $this->dispatch('pos:beneficio', detalleId: $id)->to(AccionesProducto::class);
    }

    public function openCancel(int $id): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.anular_enviados');
        $this->pedido();
        $this->exigirGuardado();
        $this->dispatch('pos:anulacion', detalleId: $id)->to(AccionesProducto::class);
    }

    public function requestDiscard(): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.editar');
        $this->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);
        $this->versionDescarte = $this->version;
        $this->motivoConfirmado = $this->motivo;
        $this->showDiscard = false;
        $this->confirmar('Descartar borrador', 'Se conservará el pedido como anulado y se liberará su mesa si corresponde. No se modificarán existencias.', 'confirmDiscard', 'Descartar borrador', peligro: true);
    }

    public function confirmDiscard(GestionPedidos $gestion): void
    {
        try {
            $gestion->descartar($this->actor(), $this->pedidoId, $this->versionDescarte, $this->motivoConfirmado);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        Alertas::flash('Borrador descartado.');
        $this->redirectRoute('pos.index', navigate: true);
    }

    /** @return array<string,array{detalleId:string,productoId:string,cantidad:string,nota:string}> */
    private function paraVista(mixed $entrada): array
    {
        if (! is_array($entrada)) {
            return [];
        }
        $resultado = [];
        foreach (array_slice($entrada, 0, 50, true) as $clave => $fila) {
            if (! is_string($clave) || ! is_array($fila)) {
                continue;
            }
            foreach (['detalleId', 'productoId', 'cantidad', 'nota'] as $campo) {
                if (! isset($fila[$campo]) || ! is_string($fila[$campo])) {
                    continue 2;
                }
            }
            $resultado[$clave] = ['detalleId' => $fila['detalleId'], 'productoId' => $fila['productoId'], 'cantidad' => $fila['cantidad'], 'nota' => $fila['nota']];
        }

        return $resultado;
    }

    public function render(): View
    {
        $actor = $this->actor();
        $pedido = $this->pedido()->load(['mesa.salon', 'mozo', 'delivery', 'detalles.hijos', 'detalles.producto']);
        $catalogo = app(CatalogoPos::class)->consulta();
        $buscar = trim(mb_substr($this->search, 0, 100));
        if ($buscar !== '') {
            $catalogo->where(fn ($q) => $q->where('nombre', 'like', '%'.$buscar.'%')->orWhere('codigo', 'like', '%'.$buscar.'%'));
        }
        if (ctype_digit($this->categoriaId)) {
            $catalogo->where('categoria_producto_id', $this->categoriaId);
        }
        $filasVista = $this->paraVista($this->filas);
        $ids = array_values(array_filter(array_column($filasVista, 'productoId'), fn ($id) => ctype_digit($id)));
        $seleccionados = app(CatalogoPos::class)->consulta()->whereIn('id', $ids)->get()->keyBy('id');
        $clientes = collect();
        if ($actor->can('clientes.ver')) {
            $consulta = Cliente::where('activo', true)->orderBy('nombre');
            if (trim($this->clienteSearch) !== '') {
                $consulta->where(fn ($q) => $q->where('nombre', 'like', '%'.mb_substr(trim($this->clienteSearch), 0, 100).'%')->orWhere('numero_documento', 'like', '%'.mb_substr(trim($this->clienteSearch), 0, 100).'%'));
            }
            $clientes = $consulta->limit(30)->get();
            if ($this->clienteId !== '' && ctype_digit($this->clienteId) && ! $clientes->contains('id', (int) $this->clienteId)) {
                $actual = Cliente::find($this->clienteId);
                if ($actual && ($actual->activo || $pedido->cliente_id === $actual->id)) {
                    $clientes->push($actual);
                }
            }
        }
        $total = '0.00';
        foreach ($filasVista as $fila) {
            $producto = $seleccionados->get($fila['productoId']);
            $anterior = $pedido->detalles->firstWhere('id', (int) $fila['detalleId']);
            if (($producto || $anterior) && preg_match('/^[1-9][0-9]{0,3}$/', $fila['cantidad'])) {
                $precio = $anterior instanceof PedidoDetalle ? $anterior->precio_unitario : $producto->precio_venta;
                $total = bcadd($total, bcmul(CalculoProduccion::cantidad($fila['cantidad']), $precio, 2), 2);
            }
        }
        foreach ($pedido->detalles->whereNull('detalle_padre_id')->where('estado.value', 'confirmado') as $detalle) {
            $total = bcadd($total, bcsub(bcmul(bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4), $detalle->precio_unitario, 6), $detalle->descuento_importe, 2), 2);
        }
        foreach ($pedido->cobros()->where('estado', 'confirmado')->get() as $cobro) {
            $total = bcsub($total, $cobro->descuento_general, 2);
        }
        $total = bcadd($total, $pedido->delivery && $pedido->estado->value !== 'anulado' ? $pedido->delivery->costo_envio : '0', 2);
        $progreso = [];
        $envios = EnvioCocinaDetalle::whereIn('pedido_detalle_id', $pedido->detalles->pluck('id'))->get()->groupBy('pedido_detalle_id');
        foreach ($pedido->detalles->where('estado.value', 'confirmado') as $detalle) {
            $lista = $detalle->area_preparacion_id ? '0' : $detalle->cantidadVigente();
            $iniciada = '0';
            foreach ($envios->get($detalle->id, collect()) as $envio) {
                $lista = bcadd($lista, $envio->cantidad_lista, 4);
                $iniciada = bcadd($iniciada, $envio->cantidad_iniciada, 4);
            }
            $progreso[$detalle->id] = ['lista' => $lista, 'iniciada' => $iniciada, 'entregable' => bcsub($lista, $detalle->cantidad_entregada, 4)];
        }

        return view('livewire.pos.pedido-pantalla', ['pedido' => $pedido, 'productos' => $catalogo->paginate(18), 'seleccionados' => $seleccionados,
            'filasVista' => $filasVista, 'categorias' => CategoriaProducto::where('activo', true)->orderBy('orden')->get(), 'clientes' => $clientes, 'total' => $total,
            'anulaciones' => AnulacionPedido::with('detalles')->where('pedido_id', $pedido->id)->latest('id')->limit(20)->get(),
            'beneficiosHistorial' => BeneficioPedido::where('pedido_id', $pedido->id)->latest('id')->limit(20)->get(),
            'cuentaPagada' => EstadoCuenta::liquidada($pedido), 'progreso' => $progreso, 'turnos' => TurnoCaja::with('caja')->where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->get()]);
    }
}
