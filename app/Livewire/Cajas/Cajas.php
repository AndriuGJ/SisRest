<?php

namespace App\Livewire\Cajas;

use App\Concerns\ConAlertas;
use App\Models\Caja;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Cajas\GestionCajas;
use App\Services\Cajas\ResumenTurno;
use App\Support\Alertas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Cajas y arqueos')]
class Cajas extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Url(as: 'caja', except: '')]
    public string $cajaFilter = '';

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $to = '';

    public bool $showOpen = false;

    public string $cajaId = '';

    public string $responsableId = '';

    public string $fondo = '0';

    public string $observaciones = '';

    #[Locked]
    public string $clave = '';

    /** @var array{cajaId:int,responsableId:int,fondo:string,observaciones:string}|array{} */
    #[Locked]
    public array $aperturaConfirmada = [];

    public function mount(): void
    {
        $this->actor();
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return AccesoCajas::usuario($actor);
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, ['stateFilter', 'cajaFilter', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('stateFilter', 'cajaFilter', 'from', 'to');
        $this->resetPage();
    }

    public function openForm(int $cajaId): void
    {
        $actor = $this->actor();
        Gate::forUser($actor)->authorize('cajas.abrir');
        Caja::findOrFail($cajaId);
        $this->resetValidation();
        $this->cajaId = (string) $cajaId;
        $this->responsableId = (string) $actor->id;
        $this->fondo = '0';
        $this->observaciones = '';
        $this->clave = (string) Str::uuid();
        $this->aperturaConfirmada = [];
        $this->showOpen = true;
    }

    public function reviewOpen(): void
    {
        Gate::forUser($this->actor())->authorize('cajas.abrir');
        $this->validate(['cajaId' => ['required', 'integer'], 'responsableId' => ['required', 'integer'],
            'fondo' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'], 'observaciones' => ['nullable', 'string', 'max:2000']]);
        $this->aperturaConfirmada = ['cajaId' => (int) $this->cajaId, 'responsableId' => (int) $this->responsableId, 'fondo' => $this->fondo, 'observaciones' => $this->observaciones];
        $this->showOpen = false;
        $this->confirmar('Abrir turno de caja', 'Se registrará el fondo inicial en efectivo y el responsable. El fondo no se contará como una venta ni como otro ingreso.', 'confirmOpen', 'Abrir caja');
    }

    public function confirmOpen(GestionCajas $gestion): void
    {
        $datos = $this->aperturaConfirmada;
        abort_if($datos === [], 403);
        try {
            $turno = $gestion->abrir($this->actor(), $datos['cajaId'], $datos['responsableId'], $datos['fondo'], $datos['observaciones'], $this->clave);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        Alertas::flash('Caja abierta. El fondo inicial ya está registrado.');
        $this->redirectRoute('cajas.turno', $turno, navigate: true);
    }

    public function render(): View
    {
        $actor = $this->actor();
        $cajas = Caja::with(['turnos' => fn ($q) => $q->where('estado', 'abierta')->with('usuario')])->orderBy('id')->get();
        $tarjetas = [];
        foreach ($cajas as $caja) {
            $turno = $caja->turnos->first();
            $puedeVer = $turno && ($actor->can('cajas.ver_todas') || $turno->usuario_id === $actor->id);
            $tarjetas[] = ['id' => $caja->id, 'nombre' => $caja->nombre, 'activo' => $caja->activo, 'ocupada' => $turno !== null,
                'turnoId' => $puedeVer ? $turno->id : null, 'responsable' => $puedeVer ? ($turno->responsable_nombre ?? $turno->usuario->name) : null,
                'esperado' => $puedeVer ? app(ResumenTurno::class)->calcular($turno)['esperado'] : null];
        }
        $query = AccesoCajas::consulta($actor)->with(['caja', 'usuario', 'arqueos' => fn ($q) => $q->where('tipo', 'cierre')])->latest('abierta_at')->latest('id');
        if (in_array($this->stateFilter, ['abierta', 'cerrada'], true)) {
            $query->where('estado', $this->stateFilter);
        }
        if (ctype_digit($this->cajaFilter)) {
            $query->where('caja_id', $this->cajaFilter);
        }
        foreach (['from' => '>=', 'to' => '<='] as $campo => $operador) {
            if ($this->$campo !== '' && Validator::make(['fecha' => $this->$campo], ['fecha' => ['date_format:Y-m-d']])->passes()) {
                $query->whereDate('abierta_at', $operador, $this->$campo);
            }
        }
        $responsables = collect([$actor]);
        if ($actor->can('cajas.ver_todas') && $actor->can('cajas.abrir')) {
            $responsables = User::where('activo', true)->orderBy('name')->get()->filter(fn (User $u) => $u->can('cajas.ver_propias') || $u->can('cajas.ver_todas'));
        }

        return view('livewire.cajas.cajas', ['tarjetas' => $tarjetas, 'cajas' => $cajas, 'turnos' => $query->paginate(15), 'responsables' => $responsables]);
    }
}
