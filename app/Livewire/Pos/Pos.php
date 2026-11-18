<?php

namespace App\Livewire\Pos;

use App\Concerns\ConAlertas;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Salon;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Pos\AccesoPos;
use App\Services\Pos\GestionPedidos;
use App\Support\Alertas;
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
#[Title('POS')]
class Pos extends Component
{
    use ConAlertas;
    use WithPagination;

    public string $salonId = '';

    public string $modalidadFilter = '';

    public bool $showOpen = false;

    public string $turnoId = '';

    #[Locked]
    public string $clave = '';

    #[Locked]
    public string $mesaId = '';

    #[Locked]
    public string $modalidad = 'mesa';

    public string $mozoId = '';

    public string $comensales = '';

    public string $observaciones = '';

    public string $destinatario = '';

    public string $telefono = '';

    public string $direccion = '';

    public string $referencia = '';

    public string $costoEnvio = '0';

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return AccesoPos::usuario($actor);
    }

    public function mount(): void
    {
        $this->actor();
        $this->salonId = (string) (Salon::where('activo', true)->orderBy('orden')->value('id') ?? '');
    }

    public function updatedModalidadFilter(): void
    {
        $this->resetPage();
    }

    public function openForm(string $modalidad, ?int $mesaId = null): void
    {
        $actor = $this->actor();
        Gate::forUser($actor)->authorize('pedidos.crear');
        abort_unless(in_array($modalidad, ['mesa', 'rapida', 'delivery'], true), 403);
        if ($modalidad === 'mesa') {
            Mesa::findOrFail($mesaId);
        }
        $this->resetValidation();
        $this->modalidad = $modalidad;
        $this->mesaId = $modalidad === 'mesa' ? (string) $mesaId : '';
        $this->clave = (string) Str::uuid();
        $this->mozoId = (string) $actor->id;
        $this->turnoId = (string) (TurnoCaja::where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->orderBy('id')->value('id') ?? '');
        $this->comensales = $this->observaciones = $this->destinatario = $this->telefono = $this->direccion = $this->referencia = '';
        $this->costoEnvio = '0';
        $this->showOpen = true;
    }

    public function openOrder(GestionPedidos $gestion): void
    {
        $this->resetValidation();
        $this->validate(['turnoId' => ['required', 'integer']]);
        try {
            $pedido = $gestion->abrir($this->actor(), (int) $this->turnoId, $this->clave, ['modalidad' => $this->modalidad, 'mesaId' => $this->mesaId, 'mozoId' => $this->mozoId,
                'comensales' => $this->comensales, 'observaciones' => $this->observaciones, 'destinatario' => $this->destinatario,
                'telefono' => $this->telefono, 'direccion' => $this->direccion, 'referencia' => $this->referencia, 'costoEnvio' => $this->costoEnvio]);
        } catch (ValidationException $error) {
            $this->setErrorBag($error->validator->errors());

            return;
        }
        Alertas::flash('Pedido abierto. Agrega sus productos.');
        $this->redirectRoute('pos.pedido', $pedido, navigate: true);
    }

    public function render(): View
    {
        $actor = $this->actor();
        $salones = Salon::where('activo', true)->orderBy('orden')->orderBy('nombre')->get();
        $mesas = Mesa::with(['salon', 'ocupacion.pedido'])->where('salon_id', ctype_digit($this->salonId) ? $this->salonId : 0)->where('activo', true)->whereHas('salon', fn ($q) => $q->where('activo', true))->orderBy('codigo')->get();
        $tarjetas = [];
        foreach ($mesas as $mesa) {
            $pedido = $mesa->ocupacion ? $mesa->ocupacion->pedido : Pedido::where('mesa_id', $mesa->id)->whereNotIn('estado', ['cerrado', 'anulado'])->first();
            $visible = $pedido && AccesoPos::puedeVer($actor, $pedido);
            $tarjetas[] = ['id' => $mesa->id, 'codigo' => $mesa->codigo, 'capacidad' => $mesa->capacidad, 'ocupada' => $pedido !== null,
                'pedidoId' => $visible ? $pedido->id : null, 'mozo' => $visible ? ($pedido->mozo_nombre ?? $pedido->mozo?->name) : null];
        }
        $query = AccesoPos::consulta($actor)->with(['mesa', 'mozo', 'delivery'])->whereNotIn('estado', ['cerrado', 'anulado'])->latest('id');
        if (in_array($this->modalidadFilter, ['mesa', 'rapida', 'delivery'], true)) {
            $query->where('modalidad', $this->modalidadFilter);
        }
        $mozos = collect([$actor]);
        if ($actor->can('pedidos.ver_todos')) {
            $mozos = User::where('activo', true)->orderBy('name')->get()->filter(fn (User $u) => $u->can('pos.ver') && $u->can('pedidos.crear') && ($u->can('pedidos.ver_propios') || $u->can('pedidos.ver_todos')));
        }

        return view('livewire.pos.pos', ['salones' => $salones, 'tarjetas' => $tarjetas, 'pedidos' => $query->paginate(12), 'mozos' => $mozos,
            'turnos' => TurnoCaja::with('caja')->where('estado', 'abierta')->whereHas('caja', fn ($q) => $q->where('activo', true))->orderBy('id')->get()]);
    }
}
