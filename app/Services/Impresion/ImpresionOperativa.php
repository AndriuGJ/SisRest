<?php

namespace App\Services\Impresion;

use App\Enums\EstadoTrabajoImpresion;
use App\Exceptions\FalloImpresion;
use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\Impresora;
use App\Models\Pedido;
use App\Models\TrabajoImpresion;
use App\Models\User;
use App\Services\Configuracion\BloqueaImpresion;
use App\Services\Configuracion\GestionImpresoras;
use App\Services\Pos\AccesoPos;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @phpstan-import-type Contenido from FuenteImpresion
 *
 * @phpstan-type Solicitud array{tipo:string,id:int,caja:int,area:int}
 * @phpstan-type Configuracion array{nombre:string,host:string,puerto:int,ancho_mm:int}
 * @phpstan-type Plan array{huella:string,pedidoId:int,modelo:class-string,tipo:string,solicitud:Solicitud,configuracion:Configuracion,impresoraId:int,contenido:Contenido,claveDocumento:string,copiaId:int,existenteId:int|null}
 */
class ImpresionOperativa
{
    use BloqueaImpresion;

    public function __construct(private FuenteImpresion $fuentes, private TicketOperativo $ticket, private TransporteEscPos $transporte) {}

    /**
     * @param  Solicitud  $solicitud
     * @return Plan
     */
    public function previsualizar(User $actor, array $solicitud, int $copiaId = 0): array
    {
        return $this->transaccion($actor, AccesoImpresion::permiso($solicitud['tipo']), fn () => $this->calcular($actor, $solicitud, $copiaId));
    }

    /** @param Solicitud $solicitud */
    public function imprimir(User $actor, array $solicitud, int $copiaId, string $motivo, string $clave, string $huella): TrabajoImpresion
    {
        Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
        $enviar = false;
        $trabajo = $this->transaccion($actor, AccesoImpresion::permiso($solicitud['tipo']), function () use ($actor, $solicitud, $copiaId, $motivo, $clave, $huella, &$enviar): TrabajoImpresion {
            $enviar = false; // Una transacción reintentada debe volver a adquirir el envío.
            if ($existente = TrabajoImpresion::where('clave_idempotencia', $clave)->lockForUpdate()->first()) {
                AccesoImpresion::trabajo($actor, $existente);
                // MySQL normaliza las claves JSON: comparar cada valor y tipo, sin depender de su orden.
                abort_unless($existente->usuario_id === $actor->id && $existente->solicitud !== null
                    && $existente->solicitud['tipo'] === $solicitud['tipo'] && $existente->solicitud['id'] === $solicitud['id']
                    && $existente->solicitud['caja'] === $solicitud['caja'] && $existente->solicitud['area'] === $solicitud['area']
                    && ($existente->reimpresion_de_id ?? 0) === $copiaId, 403);

                return $existente;
            }
            $plan = $this->calcular($actor, $solicitud, $copiaId);
            if ($plan['existenteId']) {
                return TrabajoImpresion::findOrFail($plan['existenteId']);
            }
            if (! hash_equals($plan['huella'], $huella)) {
                throw ValidationException::withMessages(['impresion' => 'Cambió el documento, su avance o la impresora. Vuelve a abrir la vista previa.']);
            }
            if ($copiaId) {
                Validator::make(['motivo' => trim($motivo)], ['motivo' => ['required', 'string', 'min:5', 'max:500']])->validate();
            }
            app(GestionImpresoras::class)->comprobarDisponible(Impresora::findOrFail($plan['impresoraId']));
            $trabajo = TrabajoImpresion::create(['pedido_id' => $plan['pedidoId'], 'impresora_id' => $plan['impresoraId'], 'usuario_id' => $actor->id, 'usuario_nombre' => User::findOrFail($actor->id)->name,
                'tipo' => $plan['tipo'], 'documento_type' => $plan['modelo'], 'documento_id' => $solicitud['id'], 'solicitud' => $solicitud, 'configuracion' => $plan['configuracion'], 'contenido' => $plan['contenido'],
                'estado' => 'procesando', 'intentos' => 1, 'reimpresion_de_id' => $copiaId ?: null, 'motivo_reimpresion' => $copiaId ? trim($motivo) : null, 'clave_documento' => $copiaId ? null : $plan['claveDocumento'], 'clave_idempotencia' => $clave]);
            $enviar = true;
            activity()->causedBy($actor)->performedOn($trabajo)->event('impresion_registrada')->withProperties(['pedido_id' => $plan['pedidoId'], 'tipo' => $plan['tipo'], 'copia_de_id' => $copiaId ?: null])->log('Documento de impresión registrado antes de enviar a la red');

            return $trabajo;
        });
        if (! $enviar) {
            return $trabajo;
        }
        // Persistido antes de tocar la red. No reejecutar el envío dentro de una transacción o reintento.
        $iniciado = false;
        try {
            $bytes = $this->ticket->generar($this->texto($trabajo));
            $iniciado = true;
            if (! $trabajo->configuracion) {
                throw new \LogicException('Falta la conexión histórica de la impresión.');
            }
            $this->transporte->enviar($trabajo->configuracion['host'], $trabajo->configuracion['puerto'], $bytes);
            $estado = EstadoTrabajoImpresion::ENVIADO;
            $mensaje = null;
        } catch (FalloImpresion $error) {
            $estado = $error->incierto ? EstadoTrabajoImpresion::RESULTADO_INCIERTO : EstadoTrabajoImpresion::ERROR;
            $mensaje = $error->getMessage();
        } catch (Throwable $error) {
            report($error);
            $estado = $iniciado ? EstadoTrabajoImpresion::RESULTADO_INCIERTO : EstadoTrabajoImpresion::ERROR;
            $mensaje = $iniciado ? 'No se pudo confirmar el resultado. Revisa el papel antes de solicitar una copia.' : 'No se pudo generar el documento. Solicita una copia después de revisar el error.';
        }
        $cambiado = TrabajoImpresion::whereKey($trabajo->id)->where('estado', 'procesando')->update(['estado' => $estado->value, 'ultimo_error' => $mensaje, 'enviado_at' => $estado === EstadoTrabajoImpresion::ENVIADO ? now() : null]);
        if ($cambiado) {
            activity()->causedBy($actor)->performedOn($trabajo)->event('impresion_finalizada')->withProperties(['estado' => $estado->value])->log($mensaje ?? 'Bytes enviados; verificar salida física del papel');
        }

        return $trabajo->fresh() ?? $trabajo;
    }

    public function texto(TrabajoImpresion $trabajo): string
    {
        if (! $trabajo->configuracion || ($trabajo->contenido['version'] ?? null) !== 1) {
            throw ValidationException::withMessages(['impresion' => 'Este trabajo histórico no tiene una vista previa compatible.']);
        }

        return $this->ticket->texto($trabajo->contenido, $trabajo->configuracion['ancho_mm'], $trabajo->reimpresion_de_id !== null, '#'.$trabajo->id, $trabajo->created_at?->timezone('America/Lima')->format('d/m/Y H:i') ?? '', $trabajo->usuario_nombre ?? '', $trabajo->motivo_reimpresion ?? '');
    }

    /**
     * @param  Solicitud  $solicitud
     * @return Plan
     */
    private function calcular(User $actor, array $solicitud, int $copiaId): array
    {
        Validator::make(['solicitud' => $solicitud], ['solicitud' => ['required', 'array:tipo,id,caja,area'], 'solicitud.tipo' => ['required', 'in:comanda,precuenta,ticket,anulacion,cancelacion_pagada'], 'solicitud.id' => ['required', 'integer', 'min:1'], 'solicitud.caja' => ['required', 'integer', 'min:0'], 'solicitud.area' => ['required', 'integer', 'min:0']])->validate();
        $fuente = $this->fuentes->identificar($actor, $solicitud['tipo'], $solicitud['id'], $solicitud['area']);
        $claveDocumento = $solicitud['tipo'].':'.$solicitud['id'].':'.$fuente['area'];
        $original = null;
        if ($copiaId) {
            Gate::forUser(User::findOrFail($actor->id))->authorize('impresion.reimprimir');
            $original = TrabajoImpresion::lockForUpdate()->findOrFail($copiaId);
            AccesoImpresion::trabajo($actor, $original);
            abort_unless($original->reimpresion_de_id === null && $original->documento_type === $fuente['modelo'] && $original->documento_id === $solicitud['id'] && $original->solicitud && $original->solicitud['tipo'] === $solicitud['tipo'] && $original->solicitud['area'] === $solicitud['area'], 403);
            if (in_array($original->estado->value, ['pendiente', 'procesando'], true)) {
                throw ValidationException::withMessages(['impresion' => 'El envío original sigue en curso. Actualiza su estado antes de solicitar una copia.']);
            }
            if (($original->contenido['version'] ?? null) !== 1) {
                throw ValidationException::withMessages(['impresion' => 'El documento histórico requiere revisión antes de reimprimir.']);
            }
            $contenido = $original->contenido;
        } else {
            if ($solicitud['tipo'] !== 'precuenta' && ($existente = TrabajoImpresion::where('clave_documento', $claveDocumento)->first())) {
                return $this->existente($actor, $existente);
            }
            $contenido = $this->fuentes->contenido($fuente);
            if ($solicitud['tipo'] === 'precuenta') {
                $claveDocumento .= ':'.hash('sha256', json_encode($contenido, JSON_THROW_ON_ERROR));
                if ($existente = TrabajoImpresion::where('clave_documento', $claveDocumento)->first()) {
                    return $this->existente($actor, $existente);
                }
            }
        }
        if ($fuente['area']) {
            $area = AreaPreparacion::findOrFail($fuente['area']);
            $impresoras = Impresora::where('area_preparacion_id', $area->id)->where('activo', true)->get();
            if (! $area->activo || $impresoras->count() !== 1) {
                throw ValidationException::withMessages(['impresion' => 'Configura una impresora activa para el destino '.$area->nombre.'.']);
            }
            $impresora = $impresoras->firstOrFail();
        } else {
            $caja = Caja::findOrFail($solicitud['caja']);
            if ($solicitud['tipo'] === 'ticket') {
                AccesoImpresion::cajasTicket($actor)->findOrFail($caja->id);
            }
            $impresora = $caja->impresora;
            if (! $caja->activo || ! $impresora || $impresora->area_preparacion_id) {
                throw ValidationException::withMessages(['impresion' => 'Selecciona una caja activa con su impresora de caja configurada.']);
            }
        }
        if (! $impresora->activo || ! in_array($impresora->ancho_mm, [58, 80], true)) {
            throw ValidationException::withMessages(['impresion' => 'Activa la impresora y revisa su ancho de papel.']);
        }
        Validator::make(['host' => $impresora->host, 'puerto' => $impresora->puerto], ['host' => ['required', 'ipv4', 'not_in:0.0.0.0,255.255.255.255'], 'puerto' => ['required', 'integer', 'min:1', 'max:65535']])->validate();
        $configuracion = ['nombre' => $impresora->nombre, 'host' => $impresora->host ?? '', 'puerto' => $impresora->puerto, 'ancho_mm' => $impresora->ancho_mm];
        $plan = ['pedidoId' => $fuente['pedido']->id, 'modelo' => $fuente['modelo'], 'tipo' => in_array($solicitud['tipo'], ['anulacion', 'cancelacion_pagada'], true) ? 'comanda' : $solicitud['tipo'], 'solicitud' => $solicitud, 'configuracion' => $configuracion, 'impresoraId' => $impresora->id, 'contenido' => $contenido, 'claveDocumento' => $claveDocumento, 'copiaId' => $copiaId, 'existenteId' => null];
        $plan['huella'] = hash('sha256', json_encode([$plan, $impresora->getAttributes(), $original?->getAttributes()], JSON_THROW_ON_ERROR));

        return $plan;
    }

    /** @return Plan */
    private function existente(User $actor, TrabajoImpresion $trabajo): array
    {
        AccesoImpresion::trabajo($actor, $trabajo);
        abort_unless($trabajo->pedido_id && $trabajo->configuracion && $trabajo->solicitud && ($trabajo->contenido['version'] ?? null) === 1, 404);

        $modelo = FuenteImpresion::modelo($trabajo->solicitud['tipo']);
        abort_unless($trabajo->documento_type === $modelo, 404);

        return ['huella' => '', 'pedidoId' => $trabajo->pedido_id, 'modelo' => $modelo, 'tipo' => $trabajo->tipo->value, 'solicitud' => $trabajo->solicitud, 'configuracion' => $trabajo->configuracion, 'impresoraId' => $trabajo->impresora_id, 'contenido' => $trabajo->contenido, 'claveDocumento' => $trabajo->clave_documento ?? '', 'copiaId' => 0, 'existenteId' => $trabajo->id];
    }

    public function actualizar(User $actor, int $pedidoId): void
    {
        $actual = AccesoPos::usuario($actor);
        AccesoPos::pedido($actual, Pedido::findOrFail($pedidoId));
        $permiso = collect(['impresion.comandas', 'impresion.precuentas', 'impresion.tickets'])->first(fn (string $p) => $actual->can($p));
        abort_unless($permiso !== null, 403);
        $this->transaccion($actual, $permiso, fn () => null);
    }
}
