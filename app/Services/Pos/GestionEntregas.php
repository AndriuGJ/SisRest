<?php

namespace App\Services\Pos;

use App\Models\Articulo;
use App\Models\EnvioCocinaDetalle;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ReservaInventario;
use App\Models\User;
use App\Services\Inventario\CalculoProduccion;
use Illuminate\Validation\ValidationException;

class GestionEntregas
{
    use TransaccionPos;

    public function entregar(User $actor, int $pedidoId, int $detalleId, string $cantidad, string $version): void
    {
        $this->ejecutar($actor, 'entregas.confirmar', function () use ($actor, $pedidoId, $detalleId, $cantidad, $version): void {
            $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);
            AccesoPos::pedido($actor, $pedido);
            if (in_array($pedido->estado->value, ['cerrado', 'anulado'], true) || ! hash_equals(GestionPedidos::huella($pedido), $version)) {
                throw ValidationException::withMessages(['seguridad' => 'El pedido cambió. Vuelve a abrirlo y revisa la entrega.']);
            }
            $detalle = $pedido->detalles()->with('producto')->lockForUpdate()->findOrFail($detalleId);
            if ($detalle->estado->value !== 'confirmado' || ! $detalle->confirmacion_pedido_id || $detalle->producto->tipo->value === 'combo') {
                throw ValidationException::withMessages(['seguridad' => 'Selecciona un producto confirmado o un componente del combo.']);
            }
            $valor = CalculoProduccion::cantidad($cantidad);
            $pendiente = bcsub($detalle->cantidadVigente(), $detalle->cantidad_entregada, 4);
            if (bccomp($valor, $pendiente, 4) > 0) {
                throw ValidationException::withMessages(['cantidad' => 'La entrega supera la cantidad pendiente.']);
            }
            $envios = EnvioCocinaDetalle::where('pedido_detalle_id', $detalleId)->orderBy('id')->lockForUpdate()->get();
            if ($detalle->area_preparacion_id && $envios->count() !== 1) {
                throw ValidationException::withMessages(['seguridad' => 'Revisa el envío histórico de este producto.']);
            }
            if (! $detalle->area_preparacion_id && $envios->isNotEmpty()) {
                throw ValidationException::withMessages(['seguridad' => 'La entrega directa contiene un envío inesperado.']);
            }
            $reservas = ReservaInventario::where('pedido_detalle_id', $detalleId)->lockForUpdate()->get();
            $reserva = $reservas->first();
            if ($reservas->count() !== 1 || ! $reserva || bccomp(bcsub(bcsub($reserva->cantidad, $reserva->cantidad_consumida, 4), $reserva->cantidad_liberada, 4), $valor, 4) < 0) {
                throw ValidationException::withMessages(['seguridad' => 'La reserva no cubre esta entrega.']);
            }
            $articulo = Articulo::lockForUpdate()->findOrFail($reserva->articulo_id);
            if (bccomp($articulo->stock_actual, $valor, 4) < 0 || bccomp($articulo->stock_reservado, $valor, 4) < 0) {
                throw ValidationException::withMessages(['seguridad' => 'Las existencias no cubren la reserva. Revisa inventario.']);
            }
            $acumulada = bcadd($detalle->cantidad_entregada, $valor, 4);
            $saldo = bcsub($articulo->stock_actual, $valor, 4);
            MovimientoInventario::create(['articulo_id' => $articulo->id, 'usuario_id' => $actor->id, 'tipo' => 'entrega', 'cantidad' => bcsub('0', $valor, 4),
                'saldo_anterior' => $articulo->stock_actual, 'saldo_posterior' => $saldo, 'costo_unitario' => $articulo->costo_promedio,
                'clave_idempotencia' => 'entrega:'.$detalleId.':'.$acumulada, 'registrado_at' => now(), 'origen_type' => PedidoDetalle::class, 'origen_id' => $detalleId]);
            $articulo->update(['stock_actual' => $saldo, 'stock_reservado' => bcsub($articulo->stock_reservado, $valor, 4)]);
            $reserva->update(['cantidad_consumida' => bcadd($reserva->cantidad_consumida, $valor, 4)]);
            $restante = $valor;
            foreach ($envios as $envio) {
                $disponible = bcsub(bcsub($envio->cantidad, $envio->cantidad_anulada, 4), $envio->cantidad_entregada, 4);
                $tomar = bccomp($restante, $disponible, 4) < 0 ? $restante : $disponible;
                if (bccomp($tomar, '0', 4) > 0) {
                    $entregada = bcadd($envio->cantidad_entregada, $tomar, 4);
                    // La entrega física acredita al menos esta cantidad preparada.
                    // No marca la comanda como terminada por Cocina.
                    $envio->update([
                        'cantidad_entregada' => $entregada,
                        'cantidad_iniciada' => bccomp($envio->cantidad_iniciada, $entregada, 4) < 0 ? $entregada : $envio->cantidad_iniciada,
                        'cantidad_lista' => bccomp($envio->cantidad_lista, $entregada, 4) < 0 ? $entregada : $envio->cantidad_lista,
                    ]);
                    $restante = bcsub($restante, $tomar, 4);
                }
            }
            if ($detalle->area_preparacion_id && bccomp($restante, '0', 4) !== 0) {
                throw ValidationException::withMessages(['seguridad' => 'El envío no cubre la entrega. No se descontaron existencias.']);
            }
            $detalle->update(['cantidad_entregada' => $acumulada]);
            if ($detalle->detalle_padre_id) {
                $padre = PedidoDetalle::lockForUpdate()->findOrFail($detalle->detalle_padre_id);
                $unidades = $padre->cantidadVigente();
                foreach ($padre->hijos()->lockForUpdate()->get() as $hijo) {
                    if (! $hijo->factor_componente || bccomp($hijo->factor_componente, '0', 4) <= 0) {
                        throw ValidationException::withMessages(['seguridad' => 'El combo no tiene un factor histórico válido.']);
                    }
                    $completas = bcdiv($hijo->cantidad_entregada, $hijo->factor_componente, 0);
                    $unidades = bccomp($completas, $unidades, 4) < 0 ? $completas : $unidades;
                }
                $padre->update(['cantidad_entregada' => $unidades]);
            }
            EstadoAtencion::actualizar($pedido);
            activity()->causedBy($actor)->performedOn($detalle)->event('producto_entregado')->withProperties(['cantidad' => $valor, 'acumulada' => $acumulada, 'requiere_marca_cocina' => false])->log('Entrega y salida de existencias confirmadas');
        });
    }
}
