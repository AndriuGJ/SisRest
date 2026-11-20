<?php

namespace App\Livewire\Pos;

use App\Concerns\ConAlertas;
use App\Models\AnulacionPedido;
use App\Models\BeneficioPedido;
use App\Models\CategoriaProducto;
use App\Models\EnvioCocinaDetalle;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Cobros\CerrarAtencion;
use App\Services\Cobros\EstadoCuenta;
use App\Services\Inventario\CalculoProduccion;
use App\Services\Pos\AccesoPos;
use App\Services\Pos\AnularPedido;
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

    #[Locked]
    public string $clienteId = '';

    #[Locked]
    public string $comensales = '';

    #[Locked]
    public string $observaciones = '';

    public string $search = '';

    public string $categoriaId = '';

    #[Locked]
    public string $notaClave = '';

    public string $notaEdicion = '';

    public bool $showNote = false;

    public bool $showDiscard = false;

    public bool $showCancelOrder = false;

    public string $motivoAnulacionTotal = '';

    #[Locked]
    public string $motivoTotalRevisado = '';

    #[Locked]
    public string $huellaAnulacionTotal = '';

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
        foreach ($this->paraVista($this->filas) as $clave => $fila) {
            if ($fila['productoId'] === (string) $id && preg_match('/^[1-9][0-9]{0,3}$/', $fila['cantidad'])) {
                $this->filas[$clave]['cantidad'] = (string) min(9999, (int) $fila['cantidad'] + 1);

                return;
            }
        }
        if (count($this->filas) < 50) {
            $this->filas['n-'.Str::uuid()] = ['detalleId' => '', 'productoId' => (string) $id, 'cantidad' => '1', 'nota' => ''];
        }
    }

    public function cambiarCantidad(string $clave, int $cambio): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.editar');
        $this->pedido();
        abort_unless(in_array($cambio, [-1, 1], true) && isset($this->filas[$clave]), 403);
        $fila = $this->paraVista($this->filas)[$clave] ?? null;
        if ($fila) {
            $this->filas[$clave]['cantidad'] = (string) max(1, min(9999, (int) $fila['cantidad'] + $cambio));
        }
    }

    public function editarNota(string $clave): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.editar');
        $this->pedido();
        $fila = $this->paraVista($this->filas)[$clave] ?? null;
        abort_unless($fila !== null, 404);
        $this->notaClave = $clave;
        $this->notaEdicion = $fila['nota'];
        $this->showNote = true;
    }

    public function guardarNota(): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.editar');
        $this->pedido();
        $this->validate(['notaEdicion' => ['nullable', 'string', 'max:500']]);
        abort_unless(isset($this->filas[$this->notaClave]), 404);
        $this->filas[$this->notaClave]['nota'] = trim($this->notaEdicion);
        $this->showNote = false;
    }

    public function anadir(GestionPedidos $gestion): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.enviar');
        $this->resetValidation();
        $this->validate(['turnoId' => ['required', 'integer']]);
        if ($this->filas === []) {
            $this->notificar('Selecciona los productos que deseas añadir.', 'info');

            return;
        }
        $pedido = $gestion->guardar($this->actor(), $this->pedidoId, (int) $this->turnoId, $this->version,
            ['clienteId' => $this->clienteId, 'comensales' => $this->comensales, 'observaciones' => $this->observaciones], $this->filas);
        $this->filas = [];
        $this->mount($pedido->fresh());
        $this->requestSend();
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

    public function irCobro(): void
    {
        Gate::forUser($this->actor())->authorize('cobros.crear');
        $this->exigirGuardado();
        $pedido = $this->pedido();
        if ($pedido->detalles()->where('estado', 'borrador')->exists()) {
            throw ValidationException::withMessages(['seguridad' => 'Añade los productos pendientes antes de cobrar.']);
        }
        $this->redirectRoute('pos.cobrar', $pedido, navigate: true);
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
        $this->confirmar('Añadir productos al pedido', 'Se reservarán las existencias de '.$lineas.' líneas nuevas. Los productos con destino se enviarán a Cocina o Barra; los de entrega directa quedarán disponibles para entregar.', 'confirmSend', 'Confirmar y enviar');
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
        $this->confirmar('Finalizar y liberar mesa', 'Confirma que todos los consumos ya fueron entregados al cliente. Se registrarán las entregas pendientes y su salida de stock, se verificará que todo esté pagado y se liberará únicamente esta mesa. No requiere ninguna marca de Cocina ni genera otro cobro.', 'confirmClose', 'Confirmar entrega y finalizar');
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

    public function openCancelOrder(): void
    {
        Gate::forUser($this->actor())->authorize('pedidos.anular_enviados');
        $pedido = $this->pedido();
        if ($pedido->mesa_id) {
            Gate::forUser($this->actor())->authorize('mesas.liberar');
        }
        $this->resetValidation();
        $this->motivoAnulacionTotal = $this->motivoTotalRevisado = $this->huellaAnulacionTotal = '';
        $this->showCancelOrder = true;
    }

    public function reviewCancelOrder(AnularPedido $gestion): void
    {
        $this->resetValidation();
        $plan = $gestion->previsualizar($this->actor(), $this->pedidoId, $this->motivoAnulacionTotal);
        $this->motivoTotalRevisado = trim($this->motivoAnulacionTotal);
        $this->huellaAnulacionTotal = $plan['huella'];
        $this->showCancelOrder = false;
        $texto = 'Se anulará todo el pedido, incluidos los borradores, y se liberará la mesa. Los productos todavía sin preparar liberarán sus reservas.';
        if ($plan['mermas'] > 0) {
            $texto .= ' Hay productos en preparación o listos: se registrarán como merma y no volverán al stock disponible.';
        }
        $this->confirmar('Anular pedido completo', $texto.' No se devolverá dinero. Esta operación no se puede deshacer.', 'confirmCancelOrder', 'Anular todo', peligro: true);
    }

    public function confirmCancelOrder(AnularPedido $gestion): void
    {
        if ($this->huellaAnulacionTotal === '' || $this->motivoTotalRevisado === '') {
            $this->notificar('Revisa la anulación antes de confirmar.', 'warning', toast: false);

            return;
        }
        try {
            $gestion->anular($this->actor(), $this->pedidoId, $this->motivoTotalRevisado, $this->huellaAnulacionTotal);
        } catch (ValidationException $error) {
            $this->huellaAnulacionTotal = '';
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        Alertas::flash('Pedido anulado y mesa liberada.');
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
        $grupos = [];
        foreach ($pedido->detalles->whereNull('detalle_padre_id')->filter(fn ($d) => $d->estado->value !== 'borrador') as $detalle) {
            $key = hash('sha256', json_encode([$detalle->producto_id, trim($detalle->nota ?? ''), $detalle->precio_unitario], JSON_THROW_ON_ERROR));
            if (! isset($grupos[$key])) {
                $grupos[$key] = ['productoId' => $detalle->producto_id, 'nombre' => $detalle->descripcion, 'nota' => $detalle->nota ?? '', 'precio' => $detalle->precio_unitario, 'cantidad' => '0.0000', 'importe' => '0.00', 'confirmados' => collect(), 'borradores' => []];
            }
            $grupos[$key]['confirmados']->push($detalle);
            if ($detalle->estado->value === 'confirmado') {
                $cantidadOriginal = bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4);
                $grupos[$key]['cantidad'] = bcadd($grupos[$key]['cantidad'], $cantidadOriginal, 4);
                $grupos[$key]['importe'] = bcadd($grupos[$key]['importe'], bcsub(bcmul($cantidadOriginal, $detalle->precio_unitario, 2), $detalle->descuento_importe, 2), 2);
            }
        }
        foreach ($filasVista as $clave => $fila) {
            $producto = $seleccionados->get($fila['productoId']);
            $anterior = $pedido->detalles->firstWhere('id', (int) $fila['detalleId']);
            if (! $producto && ! $anterior) {
                continue;
            }
            $precio = $anterior->precio_unitario ?? $producto->precio_venta;
            $id = $anterior->producto_id ?? $producto->id;
            $key = hash('sha256', json_encode([$id, trim($fila['nota']), $precio], JSON_THROW_ON_ERROR));
            if (! isset($grupos[$key])) {
                $grupos[$key] = ['productoId' => $id, 'nombre' => $anterior->descripcion ?? $producto->nombre, 'nota' => $fila['nota'], 'precio' => $precio, 'cantidad' => '0.0000', 'importe' => '0.00', 'confirmados' => collect(), 'borradores' => []];
            }
            $grupos[$key]['borradores'][$clave] = $fila;
            if (preg_match('/^[1-9][0-9]{0,3}$/', $fila['cantidad'])) {
                $cantidad = CalculoProduccion::cantidad($fila['cantidad']);
                $grupos[$key]['cantidad'] = bcadd($grupos[$key]['cantidad'], $cantidad, 4);
                $grupos[$key]['importe'] = bcadd($grupos[$key]['importe'], bcmul($cantidad, $precio, 2), 2);
            }
        }
        $progreso = [];
        $envios = EnvioCocinaDetalle::whereIn('pedido_detalle_id', $pedido->detalles->pluck('id'))->get()->groupBy('pedido_detalle_id');
        foreach ($pedido->detalles->where('estado.value', 'confirmado') as $detalle) {
            $lista = $detalle->area_preparacion_id ? '0' : $detalle->cantidadVigente();
            $iniciada = '0';
            foreach ($envios->get($detalle->id, collect()) as $envio) {
                $lista = bcadd($lista, $envio->cantidad_lista, 4);
                $iniciada = bcadd($iniciada, $envio->cantidad_iniciada, 4);
            }
            $progreso[$detalle->id] = ['lista' => $lista, 'iniciada' => $iniciada, 'entregable' => bcsub($detalle->cantidadVigente(), $detalle->cantidad_entregada, 4)];
        }

        return view('livewire.pos.pedido-pantalla', ['pedido' => $pedido, 'productos' => $catalogo->paginate(18), 'seleccionados' => $seleccionados,
            'gruposPedido' => $grupos, 'filasVista' => $filasVista, 'categorias' => CategoriaProducto::where('activo', true)->orderBy('orden')->get(),  'total' => $total,
            'anulaciones' => AnulacionPedido::with('detalles')->where('pedido_id', $pedido->id)->latest('id')->limit(20)->get(),
            'beneficiosHistorial' => BeneficioPedido::where('pedido_id', $pedido->id)->latest('id')->limit(20)->get(),
            'cuentaPagada' => EstadoCuenta::liquidada($pedido), 'progreso' => $progreso, 'turnos' => TurnoCaja::with('caja')->where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->get()]);
    }
}
