<?php

namespace App\Services\Cobros;

use App\Models\CobroDetalle;
use App\Models\EnvioCocina;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\ReservaInventario;
use App\Models\User;
use App\Services\Pos\AccesoPos;
use App\Services\Pos\TransaccionPos;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CerrarAtencion
{
    use TransaccionPos;

    public function cerrar(User $actor, int $pedidoId, string $version): void
    {
        $this->ejecutar($actor, 'pedidos.editar', function () use ($actor, $pedidoId, $version): void {
            $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);
            AccesoPos::pedido($actor, $pedido);
            if ($pedido->mesa_id) {
                Gate::forUser(User::findOrFail($actor->id))->authorize('mesas.liberar');
            }
            if ($pedido->estado->value === 'cerrado') {
                return;
            }
            if ($pedido->estado->value === 'anulado' || ! hash_equals(EstadoCuenta::huella($pedido), $version)) {
                throw ValidationException::withMessages(['seguridad' => 'La atención cambió. Actualiza y revisa su cierre.']);
            }
            $detalles = $pedido->detalles()->orderBy('id')->lockForUpdate()->get();
            $pedido->setRelation('detalles', $detalles);
            if (! EstadoCuenta::liquidada($pedido) || $pedido->cobros()->where('estado', 'borrador')->exists()) {
                throw ValidationException::withMessages(['seguridad' => 'Quedan productos en borrador, cantidades por cobrar o envío pendiente de pago.']);
            }
            foreach ($detalles->whereNull('detalle_padre_id')->where('estado.value', 'confirmado') as $raiz) {
                $pagada = '0';
                foreach (CobroDetalle::where('pedido_detalle_id', $raiz->id)->whereHas('cobro', fn ($q) => $q->where('estado', 'confirmado'))->lockForUpdate()->get() as $linea) {
                    $pagada = bcadd($pagada, $linea->cantidad, 4);
                }
                if (bccomp($pagada, $raiz->cantidad_cobrada, 4) !== 0) {
                    throw ValidationException::withMessages(['seguridad' => 'El historial de cobros no coincide con el pedido.']);
                }
            }
            foreach ($detalles->where('estado.value', 'confirmado') as $detalle) {
                if (bccomp($detalle->cantidad_entregada, $detalle->cantidadVigente(), 4) !== 0) {
                    throw ValidationException::withMessages(['seguridad' => 'Termina las entregas antes de cerrar la atención.']);
                }
            }
            foreach (ReservaInventario::whereIn('pedido_detalle_id', $detalles->pluck('id'))->lockForUpdate()->get() as $reserva) {
                if (bccomp(bcadd($reserva->cantidad_consumida, $reserva->cantidad_liberada, 4), $reserva->cantidad, 4) !== 0) {
                    throw ValidationException::withMessages(['seguridad' => 'La atención todavía mantiene existencias reservadas.']);
                }
            }
            if (EnvioCocina::where('pedido_id', $pedidoId)->whereHas('detalles', fn ($q) => $q->whereRaw('cantidad_entregada + cantidad_anulada < cantidad'))->exists()) {
                throw ValidationException::withMessages(['seguridad' => 'Quedan cantidades pendientes de entrega en la atención.']);
            }
            $pedido->update(['estado' => 'cerrado', 'cerrado_at' => now()]);
            OcupacionMesa::where('pedido_id', $pedidoId)->delete();
            activity()->causedBy($actor)->performedOn($pedido)->event('atencion_cerrada')->log('Cuenta pagada y productos entregados; atención cerrada');
        });
    }
}
