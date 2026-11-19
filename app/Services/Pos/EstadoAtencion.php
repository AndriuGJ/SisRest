<?php

namespace App\Services\Pos;

use App\Models\EnvioCocinaDetalle;
use App\Models\Pedido;
use App\Models\PedidoDetalle;

final class EstadoAtencion
{
    // El llamador mantiene bloqueados el pedido y los recursos de inventario.
    public static function actualizar(Pedido $pedido): void
    {
        $detalles = $pedido->detalles()->with('producto')->where('estado', 'confirmado')->lockForUpdate()->get();
        $hojas = $detalles->filter(fn (PedidoDetalle $d) => $d->producto->tipo->value !== 'combo');
        $entregado = true;
        $listo = true;
        foreach ($hojas as $detalle) {
            $activa = $detalle->cantidadVigente();
            $entregado = $entregado && bccomp($detalle->cantidad_entregada, $activa, 4) >= 0;
            if ($detalle->area_preparacion_id) {
                $lista = '0';
                foreach (EnvioCocinaDetalle::where('pedido_detalle_id', $detalle->id)->lockForUpdate()->get() as $envio) {
                    $lista = bcadd($lista, $envio->cantidad_lista, 4);
                }
                $listo = $listo && bccomp($lista, $activa, 4) >= 0;
            }
        }
        if ($hojas->isNotEmpty()) {
            $nuevo = $entregado ? 'entregado' : ($listo ? 'listo' : 'en_preparacion');
            if ($pedido->estado->value !== $nuevo) {
                $pedido->update(['estado' => $nuevo]);
            }
        }
    }
}
