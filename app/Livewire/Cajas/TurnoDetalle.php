<?php

namespace App\Livewire\Cajas;

use App\Concerns\ConAlertas;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Models\PagoCompra;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Cajas\GestionCajas;
use App\Services\Cajas\ResumenTurno;
use App\Services\Compras\ImportesCompra;
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
#[Title('Turno de caja')]
class TurnoDetalle extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Locked]
    public int $turnoId;

    #[Locked]
    public string $version = '';

    #[Locked]
    public string $clave = '';

    #[Locked]
    public string $accion = '';

    /** @var array{tipo:string,monto:string,concepto:string,motivo:string}|array{} */
    #[Locked]
    public array $movimientoConfirmado = [];

    /** @var array{tipo:string,contado:string,observaciones:string}|array{} */
    #[Locked]
    public array $arqueoConfirmado = [];

    public bool $showMovement = false;

    public bool $showCount = false;

    public string $tipoMovimiento = 'ingreso';

    public string $monto = '';

    public string $concepto = '';

    public string $motivo = '';

    #[Locked]
    public string $tipoArqueo = 'parcial';

    public string $contado = '';

    public string $observaciones = '';

    public string $tipoFilter = '';

    public string $origenFilter = '';

    /** @var array{esperado:numeric-string,diferencia:numeric-string}|array{} */
    #[Locked]
    public array $revision = [];

    public function mount(TurnoCaja $turno): void
    {
        $this->turnoId = $turno->id;
        $this->turno();
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return AccesoCajas::usuario($actor);
    }

    private function turno(): TurnoCaja
    {
        $turno = TurnoCaja::findOrFail($this->turnoId);
        AccesoCajas::turno($this->actor(), $turno);

        return $turno;
    }

    public function updated(string $campo): void
    {
        if (in_array($campo, ['tipoFilter', 'origenFilter'], true)) {
            $this->resetPage('movimientosPage');
        }
    }

    public function openMovement(string $tipo): void
    {
        $permiso = ['ingreso' => 'cajas.registrar_ingreso', 'egreso' => 'cajas.registrar_egreso', 'retiro' => 'cajas.retirar'][$tipo] ?? null;
        abort_if($permiso === null, 403);
        Gate::forUser($this->actor())->authorize($permiso);
        $turno = $this->turno();
        abort_unless($turno->estado->value === 'abierta', 403);
        $this->resetValidation();
        $this->tipoMovimiento = $tipo;
        $this->monto = $this->concepto = $this->motivo = '';
        $this->version = app(ResumenTurno::class)->calcular($turno)['huella'];
        $this->clave = (string) Str::uuid();
        $this->movimientoConfirmado = $this->arqueoConfirmado = [];
        $this->accion = 'movimiento';
        $this->showMovement = true;
    }

    public function reviewMovement(): void
    {
        $this->turno();
        $this->validate(['tipoMovimiento' => ['required', 'in:ingreso,egreso,retiro'], 'monto' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/', 'numeric', 'gt:0'],
            'concepto' => ['required', 'string', 'min:3', 'max:100'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);
        $this->movimientoConfirmado = ['tipo' => $this->tipoMovimiento, 'monto' => $this->monto, 'concepto' => $this->concepto, 'motivo' => $this->motivo];
        $this->showMovement = false;
        $this->confirmar('Registrar '.$this->tipoMovimiento, 'Se registrarán S/ '.$this->monto.' como '.$this->tipoMovimiento.' de efectivo. El movimiento conservará su responsable y motivo.', 'execute', 'Registrar movimiento', peligro: $this->tipoMovimiento !== 'ingreso');
    }

    public function openCount(string $tipo): void
    {
        abort_unless(in_array($tipo, ['parcial', 'cierre'], true), 403);
        Gate::forUser($this->actor())->authorize($tipo === 'cierre' ? 'cajas.cerrar' : 'cajas.arquear');
        $turno = $this->turno();
        abort_unless($turno->estado->value === 'abierta', 403);
        $resumen = app(ResumenTurno::class)->calcular($turno);
        $this->resetValidation();
        $this->tipoArqueo = $tipo;
        $this->contado = $this->observaciones = '';
        $this->version = $resumen['huella'];
        $this->revision = ['esperado' => $resumen['esperado'], 'diferencia' => '0.00'];
        $this->clave = (string) Str::uuid();
        $this->movimientoConfirmado = $this->arqueoConfirmado = [];
        $this->accion = 'arqueo';
        $this->showCount = true;
    }

    public function reviewCount(): void
    {
        $this->turno();
        $this->validate(['contado' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'], 'observaciones' => ['nullable', 'string', 'max:2000']]);
        abort_if($this->revision === [] || $this->accion !== 'arqueo', 403);
        $diferencia = bcsub(ImportesCompra::importe($this->contado, 'contado'), $this->revision['esperado'], 2);
        if (bccomp($diferencia, '0', 2) !== 0) {
            $this->validate(['observaciones' => ['required', 'string', 'min:5', 'max:2000']], ['observaciones.required' => 'Explica la diferencia encontrada.']);
        }
        $this->revision['diferencia'] = $diferencia;
        $this->arqueoConfirmado = ['tipo' => $this->tipoArqueo, 'contado' => $this->contado, 'observaciones' => $this->observaciones];
        $this->showCount = false;
        $this->confirmar($this->tipoArqueo === 'cierre' ? 'Cerrar turno de caja' : 'Guardar arqueo parcial',
            'Efectivo contado: S/ '.$this->contado.'. Diferencia: S/ '.$diferencia.'. '.($this->tipoArqueo === 'cierre' ? 'El turno quedará cerrado y no recibirá más movimientos.' : 'El turno seguirá abierto. No se ajustará su efectivo.'), 'execute', $this->tipoArqueo === 'cierre' ? 'Cerrar caja' : 'Guardar arqueo', peligro: $this->tipoArqueo === 'cierre');
    }

    public function execute(GestionCajas $gestion): void
    {
        $actor = $this->actor();
        $this->turno();
        abort_unless(in_array($this->accion, ['movimiento', 'arqueo'], true), 403);
        try {
            if ($this->accion === 'movimiento') {
                abort_if($this->movimientoConfirmado === [], 403);
                $gestion->movimiento($actor, $this->turnoId, $this->clave, $this->version, $this->movimientoConfirmado);
            } else {
                $datos = $this->arqueoConfirmado;
                abort_if($datos === [], 403);
                $gestion->arquear($actor, $this->turnoId, $datos['tipo'], $datos['contado'], $datos['observaciones'], $this->clave, $this->version);
            }
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->accion = '';
        $this->movimientoConfirmado = $this->arqueoConfirmado = [];
        $this->resetPage('movimientosPage');
        $this->resetPage('arqueosPage');
        $this->notificar('Operación de caja registrada.');
    }

    public function render(): View
    {
        $turno = $this->turno()->load(['caja', 'usuario']);
        $cierre = $turno->arqueos()->where('tipo', 'cierre')->first();
        $resumen = $turno->estado->value === 'cerrada' && $cierre?->resumen ? $cierre->resumen : app(ResumenTurno::class)->calcular($turno);
        $query = MovimientoCaja::with('usuario')->where('turno_caja_id', $turno->id)->latest('registrado_at')->latest('id');
        if (in_array($this->tipoFilter, ['ingreso', 'egreso', 'retiro'], true)) {
            $query->where('tipo', $this->tipoFilter);
        }
        if ($this->origenFilter === 'manual') {
            $query->whereNull('origen_type');
        } elseif ($this->origenFilter === 'proveedor') {
            $query->where('origen_type', PagoCompra::class);
        } elseif ($this->origenFilter === 'venta') {
            $query->where('origen_type', Pago::class);
        } elseif ($this->origenFilter === 'otros') {
            $query->whereNotNull('origen_type')->whereNotIn('origen_type', [PagoCompra::class, Pago::class]);
        }

        return view('livewire.cajas.turno-detalle', ['turno' => $turno, 'resumen' => $resumen, 'cierre' => $cierre,
            'movimientos' => $query->paginate(15, pageName: 'movimientosPage'),
            'arqueos' => $turno->arqueos()->with('usuario')->latest('contado_at')->latest('id')->paginate(10, pageName: 'arqueosPage')]);
    }
}
