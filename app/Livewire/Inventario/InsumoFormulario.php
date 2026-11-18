<?php

namespace App\Livewire\Inventario;

use App\Concerns\ConPresentaciones;
use App\Models\Articulo;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Inventario\GestionInsumos;
use App\Support\Alertas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Configurar insumo')]
class InsumoFormulario extends Component
{
    use ConPresentaciones;

    #[Locked]
    public ?int $insumoId = null;

    public string $codigo = '';

    public string $nombre = '';

    public string $unidadId = '';

    public string $minimo = '0';

    public bool $activo = true;

    public function mount(?Articulo $insumo = null): void
    {
        if ($insumo?->exists) {
            $insumo = Articulo::query()->with('presentaciones')->findOrFail($insumo->id);
            abort_unless($insumo->tipo->value === 'insumo', 404);
            $this->insumoId = $insumo->id;
            $this->codigo = $insumo->codigo;
            $this->nombre = $insumo->nombre;
            $this->unidadId = (string) $insumo->unidad_medida_id;
            $this->minimo = $insumo->stock_minimo;
            $this->activo = $insumo->activo;
            $this->cargarPresentaciones($insumo);
        }
        $this->autorizarFormulario();
    }

    protected function autorizarFormulario(): void
    {
        Gate::authorize('inventario.ver');
        Gate::authorize($this->insumoId ? 'insumos.editar' : 'insumos.crear');
    }

    public function save(GestionInsumos $gestion): void
    {
        $this->autorizarFormulario();
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $gestion->guardar($actor, $this->insumoId, [
            'codigo' => $this->codigo, 'nombre' => $this->nombre, 'unidadId' => $this->unidadId, 'minimo' => $this->minimo, 'activo' => $this->activo,
        ], $this->presentaciones);
        Alertas::flash($this->insumoId ? 'Insumo actualizado.' : 'Insumo registrado.');
        $this->redirectRoute('insumos.index', navigate: true);
    }

    public function render(): View
    {
        $this->autorizarFormulario();

        return view('livewire.inventario.insumo-formulario', ['unidades' => UnidadMedida::query()->where('activo', true)->orderBy('nombre')->get()]);
    }
}
