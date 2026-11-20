<?php

namespace App\Livewire\Inventario;

use App\Enums\EstadoPreparacion;
use App\Models\Preparacion;
use App\Models\Producto;
use App\Models\User;
use App\Services\Inventario\GestionPreparaciones;
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
#[Title('Preparación diaria')]
class PreparacionFormulario extends Component
{
    #[Locked]
    public ?int $preparacionId = null;

    #[Locked]
    public string $claveRegistro;

    #[Locked]
    public string $version = '';

    #[Locked]
    public string $calculoHash = '';

    public string $productoId = '';

    public string $cantidad = '1';

    public string $fecha = '';

    public string $observaciones = '';

    public string $motivoAjuste = '';

    /** @var array<int|string, string> */
    public array $consumos = [];

    /** @var array<int, array{articuloId: int, nombre: string, unidad: string, estandar: string, disponible: string}> */
    public array $filas = [];

    public function mount(?Preparacion $preparacion = null): void
    {
        $this->autorizar();
        $this->fecha = now()->format('Y-m-d');
        $this->claveRegistro = (string) Str::uuid();
        if ($preparacion?->exists) {
            $preparacion = Preparacion::query()->with('detalles.articulo.unidadMedida')->findOrFail($preparacion->id);
            abort_unless($preparacion->estado === EstadoPreparacion::BORRADOR, 403);
            abort_unless($preparacion->usuario_id === Auth::id() || Auth::user()?->can('preparaciones.confirmar'), 403);
            $this->preparacionId = $preparacion->id;
            $this->claveRegistro = $preparacion->clave_registro ?? $this->claveRegistro;
            $this->version = GestionPreparaciones::huellaBorrador($preparacion);
            $this->productoId = (string) $preparacion->producto_id;
            $this->cantidad = $preparacion->cantidad_producida;
            $this->fecha = $preparacion->fecha->format('Y-m-d');
            $this->observaciones = $preparacion->observaciones ?? '';
            $this->motivoAjuste = $preparacion->motivo_ajuste ?? '';
            $this->calculoHash = GestionPreparaciones::huellaCalculo($preparacion->producto_id, $preparacion->cantidad_producida, $preparacion->receta_huella ?? '');
            foreach ($preparacion->detalles as $fila) {
                $this->filas[$fila->articulo_id] = ['articuloId' => $fila->articulo_id, 'nombre' => $fila->descripcion ?? $fila->articulo->nombre,
                    'unidad' => $fila->unidad_codigo ?? $fila->articulo->unidadMedida->codigo, 'estandar' => $fila->cantidad_estandar, 'disponible' => $fila->articulo->disponible];
                $this->consumos[$fila->articulo_id] = $fila->cantidad_utilizada;
            }
        }
    }

    private function autorizar(): void
    {
        Gate::authorize('preparaciones.ver');
        Gate::authorize('preparaciones.crear');
    }

    public function updatedProductoId(): void
    {
        $this->reset('calculoHash', 'consumos', 'filas', 'motivoAjuste');
    }

    public function calcular(GestionPreparaciones $gestion): void
    {
        $this->autorizar();
        $this->validate(['productoId' => ['required', 'integer']]);
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $calculo = $gestion->previsualizar($actor, (int) $this->productoId, $this->cantidad);
        $this->resetValidation();
        $this->calculoHash = $calculo['calculoHash'];
        $this->filas = $calculo['ingredientes'];
        $this->consumos = [];
        $this->motivoAjuste = '';
        foreach ($this->filas as $id => $fila) {
            $this->consumos[$id] = $fila['estandar'];
        }
    }

    public function save(GestionPreparaciones $gestion): void
    {
        $this->autorizar();
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $preparacion = $gestion->guardar($actor, $this->preparacionId, $this->claveRegistro, [
            'productoId' => $this->productoId, 'cantidad' => $this->cantidad, 'fecha' => $this->fecha,
            'observaciones' => $this->observaciones, 'motivoAjuste' => $this->motivoAjuste,
        ], $this->consumos, $this->calculoHash, $this->version);
        Alertas::flash('Borrador guardado. Las existencias se actualizarán al confirmar.');
        $this->redirectRoute('preparaciones.ver', ['preparacion' => $preparacion->id], navigate: true);
    }

    public function render(): View
    {
        $this->autorizar();

        return view('livewire.inventario.preparacion-formulario', [
            'productos' => Producto::query()->where(function ($query) {
                $query->where('activo', true)->whereHas('articulo', fn ($articulo) => $articulo->where('tipo', 'preparado')->where('activo', true))
                    ->whereHas('receta', fn ($receta) => $receta->where('activo', true));
            })->when($this->preparacionId, fn ($query) => $query->orWhere('id', $this->productoId))->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }
}
