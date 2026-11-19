<?php

namespace App\Services\Cobros;

use App\Models\Cobro;
use App\Models\Pedido;

final class EstadoCuenta
{
    public static function huella(Pedido $pedido): string
    {
        return hash('sha256', json_encode([$pedido->getAttributes(), $pedido->detalles()->orderBy('id')->get()->toArray(),
            $pedido->delivery?->getAttributes(), $pedido->cobros()->with(['detalles', 'pagos'])->orderBy('id')->get()->toArray()], JSON_THROW_ON_ERROR));
    }

    /** @return numeric-string */
    public static function envioPendiente(Pedido $pedido, bool $bloquear = false): string
    {
        $pagado = '0.00';
        $query = Cobro::where('pedido_id', $pedido->id)->where('estado', 'confirmado')->orderBy('id');
        if ($bloquear) {
            $query->lockForUpdate();
        }
        foreach ($query->get() as $cobro) {
            $pagado = bcadd($pagado, $cobro->cargo_envio, 2);
        }

        return bcsub($pedido->delivery ? $pedido->delivery->costo_envio : '0', $pagado, 2);
    }

    public static function liquidada(Pedido $pedido): bool
    {
        $lineas = $pedido->detalles;
        if ($lineas->where('estado.value', 'borrador')->isNotEmpty() || $lineas->whereNull('detalle_padre_id')->where('estado.value', 'confirmado')->isEmpty() || bccomp(self::envioPendiente($pedido), '0', 2) !== 0) {
            return false;
        }
        foreach ($lineas->whereNull('detalle_padre_id')->where('estado.value', 'confirmado') as $linea) {
            if (bccomp($linea->cantidad_cobrada, bcsub($linea->cantidad, $linea->cantidad_anulada, 4), 4) < 0) {
                return false;
            }
        }

        return true;
    }
}
