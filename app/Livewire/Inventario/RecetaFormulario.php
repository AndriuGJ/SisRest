<?php

namespace App\Livewire\Inventario;

use App\Models\Articulo;
use App\Models\Producto;
use App\Models\User;
use App\Services\Inventario\GestionRecetas;
use App\Support\Alertas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Receta')]
class RecetaFormulario extends Component
{
    #[Locked]
    public int $productoId;

    #[Locked]
    public string $version = '';

    public string $rendimiento = '1';

    public string $observaciones = '';

    public bool $activo = true;

    /** @var array<string, array{articuloId: string, cantidad: string}> */
    public array $ingredientes = [];

    public function mount(Producto $producto): void
    {
        $this->autorizar();
        app(GestionRecetas::class)->comprobarProducto($producto, false);
        $this->productoId = $producto->id;
        if ($receta = $producto->receta) {
            $this->version = GestionRecetas::huella($receta);
            $this->rendimiento = $receta->rendimiento;
            $this->observaciones = $receta->observaciones ?? '';
            $this->activo = $receta->activo;
            foreach ($receta->detalles as $fila) {
                $this->ingredientes['i-'.$fila->id] = ['articuloId' => (string) $fila->articulo_id, 'cantidad' => $fila->cantidad];
            }
        }
    }

    private function autorizar(): void
    {
        Gate::authorize('productos.ver');
        Gate::authorize('recetas.gestionar');
    }

    public function addIngredient(): void
    {
        $this->autorizar();
        if (count($this->ingredientes) < 50) {
            $this->ingredientes['n-'.Str::uuid()] = ['articuloId' => '', 'cantidad' => '1'];
        }
    }

    public function removeIngredient(string $clave): void
    {
        $this->autorizar();
        unset($this->ingredientes[$clave]);
    }

    public function save(GestionRecetas $gestion): void
    {
        $this->autorizar();
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $gestion->guardar($actor, $this->productoId, $this->rendimiento, $this->observaciones, $this->activo, $this->ingredientes, $this->version);
        Alertas::flash('Receta guardada. No se modificaron las existencias.');
        $this->redirectRoute('productos.index', navigate: true);
    }

    public function render(): View
    {
        $this->autorizar();
        $producto = Producto::query()->with('articulo.unidadMedida')->findOrFail($this->productoId);

        return view('livewire.inventario.receta-formulario', [
            'producto' => $producto,
            'articulos' => Articulo::query()->with('unidadMedida')->whereKeyNot($producto->articulo_id)->orderBy('nombre')->get(),
        ]);
    }
}
