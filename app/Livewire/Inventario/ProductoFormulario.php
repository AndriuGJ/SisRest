<?php

namespace App\Livewire\Inventario;

use App\Concerns\ConPresentaciones;
use App\Models\AreaPreparacion;
use App\Models\CategoriaProducto;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Inventario\GestionProductos;
use App\Support\Alertas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Configurar producto')]
class ProductoFormulario extends Component
{
    use ConPresentaciones;
    use WithFileUploads;

    #[Locked]
    public ?int $productoId = null;

    #[Locked]
    public string $imagenActual = '';

    public string $codigo = '';

    public string $nombre = '';

    public string $descripcion = '';

    public string $tipo = 'plato';

    public string $origen = 'preparado';

    public string $unidadId = '';

    public string $categoriaId = '';

    public string $destinoId = '';

    public string $precio = '0';

    public string $minimo = '0';

    public bool $visible = true;

    public bool $activo = true;

    public bool $quitarFoto = false;

    public ?TemporaryUploadedFile $foto = null;

    /** @var array<string, array{productoId: string, cantidad: string}> */
    public array $componentes = [];

    public function mount(?Producto $producto = null): void
    {
        if ($producto?->exists) {
            $producto = Producto::query()->with(['articulo.presentaciones', 'componentes'])->findOrFail($producto->id);
            $this->productoId = $producto->id;
            $this->codigo = $producto->codigo;
            $this->nombre = $producto->nombre;
            $this->descripcion = $producto->descripcion ?? '';
            $this->tipo = $producto->tipo->value;
            $this->origen = $producto->articulo?->tipo->value ?? 'preparado';
            $this->unidadId = (string) ($producto->articulo->unidad_medida_id ?? '');
            $this->categoriaId = (string) ($producto->categoria_producto_id ?? '');
            $this->destinoId = (string) ($producto->area_preparacion_id ?? '');
            $this->precio = $producto->precio_venta;
            $this->minimo = $producto->articulo->stock_minimo ?? '0';
            $this->visible = $producto->visible_pos;
            $this->activo = $producto->activo;
            $this->imagenActual = $producto->imagen_ruta ?? '';
            $this->cargarPresentaciones($producto->articulo);
            foreach ($producto->componentes as $fila) {
                $this->componentes['c-'.$fila->id] = ['productoId' => (string) $fila->producto_id, 'cantidad' => $fila->cantidad];
            }
        } else {
            $this->unidadId = (string) (UnidadMedida::query()->where('codigo', 'porcion')->value('id') ?? '');
        }
        $this->autorizarFormulario();
    }

    protected function autorizarFormulario(): void
    {
        Gate::authorize('productos.ver');
        Gate::authorize($this->productoId ? 'productos.editar' : 'productos.crear');
    }

    public function updatedTipo(): void
    {
        $this->autorizarFormulario();
        if ($this->productoId === null && in_array($this->tipo, ['plato', 'menu', 'reventa'], true)) {
            $this->origen = $this->tipo === 'reventa' ? 'reventa' : 'preparado';
            $this->unidadId = (string) (UnidadMedida::query()->where('codigo', $this->origen === 'reventa' ? 'und' : 'porcion')->value('id') ?? '');
        }
        $this->resetValidation();
    }

    public function addComponent(): void
    {
        $this->autorizarFormulario();
        if (count($this->componentes) < 20) {
            $this->componentes['n-'.Str::uuid()] = ['productoId' => '', 'cantidad' => '1'];
        }
    }

    public function removeComponent(string $clave): void
    {
        $this->autorizarFormulario();
        unset($this->componentes[$clave]);
    }

    public function save(GestionProductos $gestion): void
    {
        $this->autorizarFormulario();
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $gestion->guardar($actor, $this->productoId, [
            'codigo' => $this->codigo, 'nombre' => $this->nombre, 'descripcion' => $this->descripcion, 'tipo' => $this->tipo,
            'origen' => $this->origen, 'unidadId' => $this->unidadId, 'categoriaId' => $this->categoriaId, 'destinoId' => $this->destinoId,
            'precio' => $this->precio, 'minimo' => $this->minimo, 'visible' => $this->visible, 'activo' => $this->activo, 'quitarFoto' => $this->quitarFoto,
        ], $this->componentes, $this->presentaciones, $this->foto);
        Alertas::flash($this->productoId ? 'Producto actualizado.' : 'Producto registrado.');
        $this->redirectRoute('productos.index', navigate: true);
    }

    public function render(): View
    {
        $this->autorizarFormulario();

        return view('livewire.inventario.producto-formulario', [
            'categorias' => CategoriaProducto::query()->orderBy('orden')->orderBy('nombre')->get(),
            'destinos' => AreaPreparacion::query()->orderBy('nombre')->get(),
            'unidades' => UnidadMedida::query()->where('activo', true)->where('magnitud', 'unidad')->orderBy('nombre')->get(),
            'productosComponentes' => Producto::query()->where('tipo', '<>', 'combo')->orderBy('nombre')->get(['id', 'nombre', 'codigo', 'activo']),
        ]);
    }
}
