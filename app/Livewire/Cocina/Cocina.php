<?php

namespace App\Livewire\Cocina;

use App\Concerns\ConAlertas;
use App\Models\AreaPreparacion;
use App\Models\EnvioCocina;
use App\Models\User;
use App\Services\Cocina\GestionCocina;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Cocina')]
class Cocina extends Component
{
    use ConAlertas;
    use WithPagination;

    public string $areaId = '';

    public string $estado = 'pendientes';

    /** @var array<int,string> */
    public array $cantidades = [];

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && $actor->activo, 403);
        $actual = User::findOrFail($actor->id);
        abort_unless($actual->activo, 403);
        Gate::forUser($actual)->authorize('cocina.ver');

        return $actual;
    }

    public function mount(): void
    {
        $this->actor();
    }

    public function updatedAreaId(): void
    {
        $this->resetPage();
    }

    public function updatedEstado(): void
    {
        $this->resetPage();
    }

    public function avanzar(int $id, string $accion, string $version, GestionCocina $gestion): void
    {
        try {
            $this->validate(['cantidades' => ['array'], 'cantidades.*' => ['string', 'max:20']]);
            $gestion->avanzar($this->actor(), $id, $accion, $this->cantidades[$id] ?? '1', $version);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        unset($this->cantidades[$id]);
        $this->notificar($accion === 'iniciar' ? 'Preparación iniciada.' : 'Productos listos para entregar.');
    }

    public function render(): View
    {
        $this->actor();
        $query = EnvioCocina::with(['pedido', 'areaPreparacion', 'detalles.detallePedido.padre'])->whereHas('pedido', fn ($q) => $q->whereNotIn('estado', ['cerrado', 'anulado']))
            ->whereHas('detalles', function ($q) {
                $q->whereRaw('cantidad_entregada + cantidad_anulada < cantidad');
                if ($this->estado === 'pendientes') {
                    $q->whereRaw('cantidad_lista + cantidad_anulada < cantidad');
                } elseif ($this->estado === 'listos') {
                    $q->whereColumn('cantidad_lista', '>', 'cantidad_entregada');
                }
            })->orderBy('enviado_at')->orderBy('id');
        if (ctype_digit($this->areaId)) {
            $query->where('area_preparacion_id', $this->areaId);
        }

        return view('livewire.cocina.cocina', ['envios' => $query->paginate(12), 'areas' => AreaPreparacion::where('activo', true)->orderBy('nombre')->get()]);
    }
}
