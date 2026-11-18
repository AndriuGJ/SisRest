<?php

namespace App\Livewire\Pos;

use App\Concerns\ConAlertas;
use App\Models\CategoriaProducto;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Inventario\CalculoProduccion;
use App\Services\Pos\AccesoPos;
use App\Services\Pos\CatalogoPos;
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
        $pedido = $this->pedido()->load(['mesa.salon', 'mozo', 'delivery', 'detalles']);
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
            $total = bcadd($total, bcmul(bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4), $detalle->precio_unitario, 6), 2);
        }
        $total = bcadd($total, $pedido->delivery ? $pedido->delivery->costo_envio : '0', 2);

        return view('livewire.pos.pedido-pantalla', ['pedido' => $pedido, 'productos' => $catalogo->paginate(18), 'seleccionados' => $seleccionados,
            'filasVista' => $filasVista, 'categorias' => CategoriaProducto::where('activo', true)->orderBy('orden')->get(), 'clientes' => $clientes, 'total' => $total,
            'turnos' => TurnoCaja::with('caja')->where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->get()]);
    }
}
