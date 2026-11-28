<?php

namespace App\Livewire\Inventario;

use App\Concerns\ConAlertas;
use App\Enums\TipoProducto;
use App\Models\CategoriaProducto;
use App\Models\Producto;
use App\Models\User;
use App\Services\Inventario\GestionProductos;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Productos para la venta')]
class Productos extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Url(as: 'tipo', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'categoria', except: '')]
    public string $categoryFilter = '';

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    #[Locked]
    public ?int $stateId = null;

    #[Locked]
    public bool $nextState = false;

    public function mount(): void
    {
        Gate::authorize('productos.ver');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStateFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'stateFilter', 'typeFilter', 'categoryFilter');
        $this->resetPage();
    }

    public function requestStateChange(int $id): void
    {
        Gate::authorize('productos.editar');
        $registro = Producto::query()->findOrFail($id);
        $this->resetValidation();
        $this->stateId = $id;
        $this->nextState = ! $registro->activo;
        $this->confirmar($this->nextState ? 'Activar producto' : 'Desactivar producto',
            $registro->nombre.'. Se conserva el historial y las existencias.', 'confirmState',
            $this->nextState ? 'Activar producto' : 'Desactivar producto', peligro: ! $this->nextState);
    }

    public function confirmState(GestionProductos $gestion): void
    {
        abort_if($this->stateId === null, 404);
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        try {
            $gestion->cambiarEstado($actor, $this->stateId, $this->nextState);
        } catch (ValidationException $error) {
            $this->setErrorBag($error->validator->errors());
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->notificar($this->nextState ? 'Producto activado.' : 'Producto desactivado.');
        $this->reset('stateId');
    }

    public function render(): View
    {
        Gate::authorize('productos.ver');
        $consulta = Producto::query()->with(['categoria', 'areaPreparacion', 'articulo.unidadMedida', 'receta', 'componentes.producto.articulo', 'componentes.producto.areaPreparacion'])->orderBy('nombre')->orderBy('id');
        $buscar = trim(mb_substr($this->search, 0, 100));
        if ($buscar !== '') {
            $consulta->where(fn (Builder $query) => $query->where('nombre', 'like', '%'.$buscar.'%')->orWhere('codigo', 'like', '%'.$buscar.'%'));
        }
        if (TipoProducto::tryFrom($this->typeFilter)) {
            $consulta->where('tipo', $this->typeFilter);
        }
        if (ctype_digit($this->categoryFilter)) {
            $consulta->where('categoria_producto_id', $this->categoryFilter);
        }
        if (in_array($this->stateFilter, ['activo', 'inactivo'], true)) {
            $consulta->where('activo', $this->stateFilter === 'activo');
        }

        return view('livewire.inventario.productos', [
            'productos' => $consulta->paginate(15),
            'categorias' => CategoriaProducto::query()->orderBy('orden')->orderBy('nombre')->get(),
        ]);
    }
}
