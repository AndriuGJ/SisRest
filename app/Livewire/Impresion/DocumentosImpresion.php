<?php

namespace App\Livewire\Impresion;

use App\Concerns\ConAlertas;
use App\Models\AnulacionPedido;
use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\EnvioCocina;
use App\Models\Pedido;
use App\Models\ResolucionDevolucion;
use App\Models\TrabajoImpresion;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Impresion\AccesoImpresion;
use App\Services\Impresion\FuenteImpresion;
use App\Services\Impresion\ImpresionOperativa;
use App\Services\Impresion\TicketOperativo;
use App\Services\Pos\AccesoPos;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** @phpstan-import-type Solicitud from ImpresionOperativa */
#[Layout('layouts.app')]
#[Title('Impresión de documentos')]
class DocumentosImpresion extends Component
{
    use ConAlertas;
    use WithPagination;

    #[Locked]
    public int $pedidoId;

    #[Locked]
    public ?int $cobroId = null;

    public string $cajaId = '';

    public bool $showPreview = false;

    public string $motivoCopia = '';

    #[Locked]
    public string $motivoRevisado = '';

    /** @var Solicitud|array{} */
    #[Locked]
    public array $solicitud = [];

    #[Locked]
    public int $copiaId = 0;

    #[Locked]
    public ?int $trabajoVisto = null;

    #[Locked]
    public string $texto = '';

    #[Locked]
    public string $nombreImpresora = '';

    #[Locked]
    public int $ancho = 80;

    #[Locked]
    public bool $puedeEnviar = false;

    #[Locked]
    public string $huella = '';

    #[Locked]
    public string $clave = '';

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $actual = AccesoPos::usuario($actor);
        AccesoPos::pedido($actual, Pedido::findOrFail($this->pedidoId));
        if ($this->cobroId) {
            AccesoImpresion::cobro($actual, Cobro::findOrFail($this->cobroId));
        } else {
            abort_unless($actual->can('impresion.comandas') || $actual->can('impresion.precuentas'), 403);
        }

        return $actual;
    }

    public function mount(?Pedido $pedido = null, ?Cobro $cobro = null): void
    {
        if ($cobro !== null && $cobro->exists) {
            $this->cobroId = $cobro->id;
            $this->pedidoId = $cobro->pedido_id;
            $cajaOrigen = $cobro->turnoCaja->caja_id;
        } else {
            abort_unless($pedido !== null && $pedido->exists, 404);
            $this->pedidoId = $pedido->id;
            $turno = $pedido->turno_apertura_id ? TurnoCaja::find($pedido->turno_apertura_id) : null;
            $cajaOrigen = $turno ? $turno->caja_id : null;
        }
        $this->actor();
        $cajas = $this->cobroId ? AccesoImpresion::cajasTicket($this->actor())->get() : Caja::where('activo', true)->orderBy('id')->get();
        $elegida = $cajas->firstWhere('id', $cajaOrigen) ?? $cajas->first();
        $this->cajaId = $elegida ? (string) $elegida->id : '';
    }

    public function openPreview(string $tipo, int $id, int $area = 0): void
    {
        $this->abrir(['tipo' => $tipo, 'id' => $id, 'caja' => in_array($tipo, ['precuenta', 'ticket'], true) ? (ctype_digit($this->cajaId) ? (int) $this->cajaId : 0) : 0, 'area' => $area], 0);
    }

    /** @param Solicitud $solicitud */
    private function abrir(array $solicitud, int $copiaId): void
    {
        $actor = $this->actor();
        abort_unless($this->cobroId ? $solicitud['tipo'] === 'ticket' && $solicitud['id'] === $this->cobroId : $solicitud['tipo'] !== 'ticket', 403);
        $this->resetValidation();
        $this->showPreview = false;
        try {
            $plan = app(ImpresionOperativa::class)->previsualizar($actor, $solicitud, $copiaId);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        abort_unless($plan['pedidoId'] === $this->pedidoId, 403);
        if ($plan['existenteId']) {
            $this->viewJob($plan['existenteId']);

            return;
        }
        $this->solicitud = $solicitud;
        $this->copiaId = $copiaId;
        $this->huella = $plan['huella'];
        $this->ancho = $plan['configuracion']['ancho_mm'];
        $this->nombreImpresora = $plan['configuracion']['nombre'];
        $this->texto = app(TicketOperativo::class)->texto($plan['contenido'], $this->ancho, $copiaId > 0);
        $this->trabajoVisto = null;
        $this->motivoCopia = $this->motivoRevisado = $this->clave = '';
        $this->puedeEnviar = true;
        $this->showPreview = true;
    }

    private function trabajo(int $id): TrabajoImpresion
    {
        $trabajo = TrabajoImpresion::findOrFail($id);
        AccesoImpresion::trabajo($this->actor(), $trabajo);
        abort_unless($trabajo->pedido_id === $this->pedidoId && ($this->cobroId ? $trabajo->solicitud && $trabajo->solicitud['tipo'] === 'ticket' && $trabajo->solicitud['id'] === $this->cobroId : $trabajo->tipo->value !== 'ticket'), 403);

        return $trabajo;
    }

    public function viewJob(int $id): void
    {
        $trabajo = $this->trabajo($id);
        $this->texto = app(ImpresionOperativa::class)->texto($trabajo);
        $this->ancho = $trabajo->configuracion['ancho_mm'] ?? 80;
        $this->nombreImpresora = $trabajo->configuracion['nombre'] ?? '';
        $this->trabajoVisto = $trabajo->id;
        $this->puedeEnviar = false;
        $this->solicitud = [];
        $this->copiaId = 0;
        $this->showPreview = true;
    }

    public function openCopy(int $id): void
    {
        $trabajo = $this->trabajo($id);
        if ($trabajo->reimpresion_de_id) {
            $trabajo = $this->trabajo($trabajo->reimpresion_de_id);
        }
        abort_unless($trabajo->solicitud !== null, 404);
        $solicitud = $trabajo->solicitud;
        if (in_array($solicitud['tipo'], ['precuenta', 'ticket'], true)) {
            $solicitud['caja'] = ctype_digit($this->cajaId) ? (int) $this->cajaId : 0;
        }
        $this->abrir($solicitud, $trabajo->id);
    }

    public function requestConfirmation(ImpresionOperativa $gestion): void
    {
        $actor = $this->actor();
        if (! $this->puedeEnviar || $this->solicitud === [] || $this->huella === '') {
            return;
        }
        if ($this->copiaId) {
            $this->validate(['motivoCopia' => ['required', 'string', 'min:5', 'max:500']]);
        }
        $plan = $gestion->previsualizar($actor, $this->solicitud, $this->copiaId);
        if ($plan['existenteId']) {
            $this->viewJob($plan['existenteId']);

            return;
        }
        if (! hash_equals($this->huella, $plan['huella'])) {
            throw ValidationException::withMessages(['impresion' => 'Cambió el documento o la configuración. Cierra y vuelve a abrir su vista previa.']);
        }
        $this->motivoRevisado = trim($this->motivoCopia);
        $this->clave = (string) Str::uuid();
        $this->showPreview = false;
        $this->confirmar($this->copiaId ? 'Reimprimir documento' : 'Imprimir documento', $this->nombreImpresora.' · '.$this->ancho.' mm. '.($this->copiaId ? 'Comprueba el papel antes de generar esta copia. ' : '').'Se enviará desde el servidor. El pedido, pago e inventario se conservan.', 'confirmPrint', $this->copiaId ? 'Imprimir copia' : 'Enviar a impresora', $this->copiaId > 0);
    }

    public function confirmPrint(ImpresionOperativa $gestion): void
    {
        $actor = $this->actor();
        if ($this->clave === '' || $this->solicitud === [] || $this->huella === '') {
            $this->notificar('Abre y revisa el documento antes de imprimir.', 'warning', toast: false);

            return;
        }
        try {
            $trabajo = $gestion->imprimir($actor, $this->solicitud, $this->copiaId, $this->motivoRevisado, $this->clave, $this->huella);
        } catch (ValidationException $error) {
            $this->notificar($error->validator->errors()->first(), 'error', toast: false);

            return;
        }
        $this->showPreview = false;
        $this->notificar(match ($trabajo->estado->value) {
            'enviado' => 'Documento enviado. Verifica que el papel salió correctamente.',
            'error' => $trabajo->ultimo_error ?? 'No se pudo enviar el documento.',
            default => 'Resultado sin confirmar. Revisa el papel antes de solicitar una copia.',
        }, match ($trabajo->estado->value) {
            'enviado' => 'success', 'error' => 'error', default => 'warning'
        }, toast: false);
    }

    public function refreshStatus(ImpresionOperativa $gestion): void
    {
        $gestion->actualizar($this->actor(), $this->pedidoId);
    }

    public function render(): View
    {
        $actor = $this->actor();
        $pedido = Pedido::findOrFail($this->pedidoId);
        $tipos = $this->cobroId ? ['ticket'] : array_values(array_filter(['comanda', 'precuenta'], fn ($t) => $actor->can(AccesoImpresion::permiso($t))));
        $historial = TrabajoImpresion::with('impresora')->where('pedido_id', $pedido->id)->whereIn('tipo', $tipos)->when($this->cobroId, fn ($q) => $q->where('documento_id', $this->cobroId)->where('documento_type', Cobro::class))->latest('id')->paginate(12);
        $avisos = [];
        if (! $this->cobroId && $actor->can('impresion.comandas')) {
            $fuentes = AnulacionPedido::where('pedido_id', $pedido->id)->get()->map(fn ($a) => ['tipo' => 'anulacion', 'id' => $a->id])->all();
            foreach (ResolucionDevolucion::where('accion', 'cancelar_pendiente')->whereHas('detalleDevolucion.devolucion.cobro', fn ($q) => $q->where('pedido_id', $pedido->id))->get() as $r) {
                $fuentes[] = ['tipo' => 'cancelacion_pagada', 'id' => $r->id];
            }
            foreach ($fuentes as $fuente) {
                foreach (collect(app(FuenteImpresion::class)->anulados($fuente['tipo'], $fuente['id']))->groupBy('area') as $area => $filas) {
                    $avisos[] = $fuente + ['area' => $area, 'destino' => AreaPreparacion::findOrFail($area)->nombre, 'filas' => $filas];
                }
            }
        }

        return view('livewire.impresion.documentos', ['pedido' => $pedido, 'cobro' => $this->cobroId ? Cobro::findOrFail($this->cobroId) : null, 'cajas' => $this->cobroId ? AccesoImpresion::cajasTicket($actor)->orderBy('id')->get() : Caja::where('activo', true)->orderBy('id')->get(),
            'envios' => EnvioCocina::with(['detalles', 'areaPreparacion'])->where('pedido_id', $pedido->id)->orderByDesc('numero_envio')->orderBy('area_preparacion_id')->get(), 'historial' => $historial, 'avisos' => $avisos]);
    }
}
