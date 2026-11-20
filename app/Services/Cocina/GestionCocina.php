<?php

namespace App\Services\Cocina;

use App\Models\EnvioCocina;
use App\Models\EnvioCocinaDetalle;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Inventario\CalculoProduccion;
use App\Services\Pos\EstadoAtencion;
use App\Services\Pos\TransaccionPos;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GestionCocina
{
    use TransaccionPos;

    public static function huella(EnvioCocinaDetalle $detalle): string
    {
        return hash('sha256', json_encode($detalle->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public static function huellaEnvio(EnvioCocina $envio): string
    {
        return hash('sha256', json_encode([$envio->getAttributes(), $envio->detalles()->orderBy('id')->get()->toArray()], JSON_THROW_ON_ERROR));
    }

    public function terminar(User $actor, int $envioId, string $version): void
    {
        $this->ejecutar($actor, 'cocina.marcar_listo', function () use ($actor, $envioId, $version): void {
            Gate::forUser(User::findOrFail($actor->id))->authorize('cocina.ver');
            $envio = EnvioCocina::lockForUpdate()->findOrFail($envioId);
            $pedido = Pedido::lockForUpdate()->findOrFail($envio->pedido_id);
            if (in_array($pedido->estado->value, ['cerrado', 'anulado'], true) || ! $envio->areaPreparacion->activo) {
                throw ValidationException::withMessages(['seguridad' => 'La comanda ya no admite cambios. Actualiza Cocina.']);
            }
            if ($envio->terminado_at !== null) {
                return;
            }
            if (! hash_equals(self::huellaEnvio($envio), $version)) {
                throw ValidationException::withMessages(['seguridad' => 'La comanda cambió. Revisa sus cantidades antes de marcar terminado.']);
            }
            foreach ($envio->detalles()->orderBy('id')->lockForUpdate()->get() as $detalle) {
                $vigente = bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4);
                if ($detalle->detallePedido->estado->value !== 'confirmado') {
                    throw ValidationException::withMessages(['seguridad' => 'Revisa los productos de esta comanda.']);
                }
                $detalle->update(['cantidad_iniciada' => $vigente, 'cantidad_lista' => $vigente]);
            }
            $envio->update(['terminado_at' => now(), 'terminado_por' => $actor->id]);
            // Marca informativa: no entrega, no cobra, no mueve stock ni libera la mesa.
            activity()->causedBy($actor)->performedOn($envio)->event('comanda_terminada')->log('Cocina marcó la comanda como terminada');
        });
    }

    public function avanzar(User $actor, int $id, string $accion, string $cantidad, string $version): void
    {
        abort_unless(in_array($accion, ['iniciar', 'listo'], true), 403);
        $permiso = $accion === 'iniciar' ? 'cocina.preparar' : 'cocina.marcar_listo';
        $this->ejecutar($actor, $permiso, function () use ($actor, $id, $accion, $cantidad, $version): void {
            Gate::forUser(User::findOrFail($actor->id))->authorize('cocina.ver');
            $lectura = EnvioCocinaDetalle::findOrFail($id);
            $envio = EnvioCocina::findOrFail($lectura->envio_cocina_id);
            $pedido = Pedido::lockForUpdate()->findOrFail($envio->pedido_id);
            $detalle = EnvioCocinaDetalle::lockForUpdate()->findOrFail($id);
            if (in_array($pedido->estado->value, ['cerrado', 'anulado'], true) || ! $envio->areaPreparacion->activo
                || $detalle->detallePedido->estado->value !== 'confirmado') {
                throw ValidationException::withMessages(['seguridad' => 'El envío ya no admite cambios. Actualiza Cocina.']);
            }
            if (! hash_equals(self::huella($detalle), $version)) {
                throw ValidationException::withMessages(['seguridad' => 'Esta línea cambió desde otro equipo. Revisa sus cantidades e inténtalo nuevamente.']);
            }
            $valor = CalculoProduccion::cantidad($cantidad);
            $campo = $accion === 'iniciar' ? 'cantidad_iniciada' : 'cantidad_lista';
            $limite = $accion === 'iniciar' ? bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4) : $detalle->cantidad_iniciada;
            $nueva = bcadd($detalle->{$campo}, $valor, 4);
            if (bccomp($nueva, $limite, 4) > 0) {
                throw ValidationException::withMessages(['cantidad' => $accion === 'iniciar' ? 'La cantidad supera lo pendiente de iniciar.' : 'Solo puedes marcar lista la cantidad que ya está en preparación.']);
            }
            $detalle->update([$campo => $nueva]);
            EstadoAtencion::actualizar($pedido);
            activity()->causedBy($actor)->performedOn($detalle)->event('cocina_'.$accion)->withProperties(['cantidad' => $valor, 'acumulada' => $nueva, 'envio_id' => $envio->id])->log('Avance de preparación registrado');
        });
    }
}
