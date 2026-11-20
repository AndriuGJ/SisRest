<?php

namespace App\Livewire\Configuracion;

use App\Concerns\ConAlertas;
use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\Impresora;
use App\Models\PruebaImpresion;
use App\Models\User;
use App\Services\Configuracion\GestionImpresoras;
use App\Services\Impresion\ProbarImpresora;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Impresoras')]
class Impresoras extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'estado', except: '')]
    public string $stateFilter = '';

    #[Url(as: 'uso', except: '')]
    public string $usageFilter = '';

    #[Locked]
    public ?int $impresoraId = null;

    public bool $showForm = false;

    public string $codigo = '';

    public string $nombre = '';

    public string $host = '';

    public string $puerto = '9100';

    public string $ancho = '80';

    public string $uso = 'general';

    public string $cajaId = '';

    public string $destinoId = '';

    public bool $activo = false;

    #[Locked]
    public ?int $testPrinterId = null;

    #[Locked]
    public ?string $testToken = null;

    #[Locked]
    public string $testHash = '';

    #[Locked]
    public ?int $stateId = null;

    #[Locked]
    public bool $nextState = false;

    public function mount(): void
    {
        Gate::authorize('impresoras.gestionar');
    }

    public function updatedUsageFilter(): void
    {
        $this->resetPage();
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
        $this->reset('search', 'stateFilter', 'usageFilter');
        $this->resetPage();
    }

    public function newRecord(): void
    {
        Gate::authorize('impresoras.gestionar');
        $this->resetValidation();
        $this->reset('impresoraId', 'codigo', 'nombre', 'host', 'puerto', 'ancho', 'uso', 'cajaId', 'destinoId', 'activo');
        $this->showForm = true;
    }

    public function editRecord(int $id): void
    {
        Gate::authorize('impresoras.gestionar');
        $registro = Impresora::query()->findOrFail($id);
        $this->resetValidation();
        $this->impresoraId = $registro->id;
        $this->codigo = $registro->codigo;
        $this->nombre = $registro->nombre;
        $this->host = $registro->host ?? '';
        $this->puerto = (string) $registro->puerto;
        $this->ancho = (string) $registro->ancho_mm;
        $this->destinoId = (string) ($registro->area_preparacion_id ?? '');
        $this->cajaId = (string) ($registro->cajas()->value('id') ?? '');
        $this->uso = $this->cajaId !== '' ? 'caja' : ($this->destinoId !== '' ? 'preparacion' : 'general');
        $this->activo = $registro->activo;
        $this->showForm = true;
    }

    public function save(GestionImpresoras $gestion): void
    {
        $gestion->guardar($this->actor(), $this->impresoraId, [
            'codigo' => $this->codigo, 'nombre' => $this->nombre, 'host' => $this->host, 'puerto' => $this->puerto,
            'ancho' => $this->ancho, 'uso' => $this->uso, 'cajaId' => $this->cajaId, 'destinoId' => $this->destinoId, 'activo' => $this->activo,
        ]);
        $this->showForm = false;
        $this->notificar($this->impresoraId ? 'Impresora actualizada.' : 'Impresora registrada.');
    }

    public function requestStateChange(int $id): void
    {
        Gate::authorize('impresoras.gestionar');
        $registro = Impresora::query()->findOrFail($id);
        $this->resetValidation();
        $this->stateId = $registro->id;
        $this->nextState = ! $registro->activo;
        $this->confirmar(
            $this->nextState ? 'Activar impresora' : 'Desactivar impresora',
            $registro->nombre.($this->nextState ? ' volverá a estar habilitada.' : ' dejará de estar disponible para nuevas atenciones. Se conserva el historial.'),
            'confirmState',
            $this->nextState ? 'Activar impresora' : 'Desactivar impresora',
            peligro: ! $this->nextState,
        );
    }

    public function confirmState(GestionImpresoras $gestion): void
    {
        abort_if($this->stateId === null, 404);
        try {
            $gestion->cambiarEstado($this->actor(), $this->stateId, $this->nextState);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->notificar($exception->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->notificar($this->nextState ? 'Impresora activada.' : 'Impresora desactivada.');
        $this->reset('stateId');
    }

    public function updatedUso(): void
    {
        $this->reset('cajaId', 'destinoId');
        $this->resetValidation();
    }

    public function requestTest(int $id): void
    {
        Gate::authorize('impresoras.gestionar');
        $impresora = Impresora::query()->findOrFail($id);
        $this->testPrinterId = $id;
        $this->testToken = (string) Str::uuid();
        $this->testHash = ProbarImpresora::huella($impresora);
        $this->confirmar('Imprimir ticket de prueba',
            $impresora->nombre.' ('.($impresora->host ?? 'sin IP').':'.$impresora->puerto.'). Se enviará un ticket físico desde el servidor.',
            'confirmTest', 'Imprimir prueba');
    }

    public function confirmTest(ProbarImpresora $servicio): void
    {
        abort_if($this->testPrinterId === null || $this->testToken === null, 404);
        try {
            $prueba = $servicio->ejecutar($this->actor(), $this->testPrinterId, $this->testToken, $this->testHash);
        } catch (ValidationException $exception) {
            $this->notificar($exception->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $tipo = match ($prueba->estado->value) {
            'enviado' => 'success', 'error' => 'error', default => 'warning',
        };
        $this->notificar($prueba->mensaje, $tipo, toast: false);
        $this->reset('testPrinterId', 'testToken', 'testHash');
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    public function render(): View
    {
        Gate::authorize('impresoras.gestionar');
        $busqueda = trim(mb_substr($this->search, 0, 100));
        $consulta = Impresora::query()->with(['areaPreparacion', 'cajas'])->orderBy('nombre')->orderBy('id');
        if ($busqueda !== '') {
            $consulta->where(fn (Builder $query) => $query->where('codigo', 'like', '%'.$busqueda.'%')
                ->orWhere('nombre', 'like', '%'.$busqueda.'%')->orWhere('host', 'like', '%'.$busqueda.'%'));
        }
        if ($this->usageFilter === 'caja') {
            $consulta->whereHas('cajas');
        } elseif ($this->usageFilter === 'preparacion') {
            $consulta->whereNotNull('area_preparacion_id');
        } elseif ($this->usageFilter === 'general') {
            $consulta->whereNull('area_preparacion_id')->whereDoesntHave('cajas');
        }
        if (in_array($this->stateFilter, ['activo', 'inactivo'], true)) {
            $consulta->where('activo', $this->stateFilter === 'activo');
        }

        return view('livewire.configuracion.impresoras', [
            'impresoras' => $consulta->paginate(15),
            'destinos' => AreaPreparacion::query()->orderBy('nombre')->get(),
            'cajas' => Caja::query()->with('impresora')->orderBy('nombre')->get(),
            'pruebas' => PruebaImpresion::query()->latest()->orderByDesc('id')->limit(10)->get(),
        ]);
    }
}
