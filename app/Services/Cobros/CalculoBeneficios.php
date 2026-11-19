<?php

namespace App\Services\Cobros;

use App\Models\CobroDetalle;
use App\Models\PedidoDetalle;
use App\Services\Compras\ImportesCompra;
use Illuminate\Validation\ValidationException;

final class CalculoBeneficios
{
    /**
     * @param  numeric-string  $base
     * @return numeric-string
     */
    public static function presupuesto(string $base, string $tipo, string $valor): string
    {
        $valor = ImportesCompra::importe($valor, 'beneficioValor', 2, true);
        if (! in_array($tipo, ['soles', 'porcentaje'], true) || ($tipo === 'porcentaje' && bccomp($valor, '100', 2) > 0)) {
            throw ValidationException::withMessages(['beneficioValor' => 'El porcentaje debe estar entre más de cero y 100.']);
        }
        $descuento = $tipo === 'porcentaje' ? ImportesCompra::redondear(bcdiv(bcmul($base, $valor, 4), '100', 6)) : $valor;
        if (bccomp($descuento, $base, 2) > 0 || bccomp($descuento, '0', 2) <= 0) {
            throw ValidationException::withMessages(['beneficioValor' => 'El descuento debe ser positivo y no superar el importe de los productos.']);
        }

        return $descuento;
    }

    /**
     * @param  numeric-string  $importe
     * @param  numeric-string  $parte
     * @param  numeric-string  $total
     * @return numeric-string
     */
    public static function repartir(string $importe, string $parte, string $total): string
    {
        if (bccomp($total, '0', 4) <= 0 || bccomp($parte, '0', 4) < 0 || bccomp($parte, $total, 4) > 0) {
            throw ValidationException::withMessages(['seguridad' => 'No se puede distribuir el beneficio con estas cantidades.']);
        }

        return bccomp($parte, $total, 4) === 0 ? $importe : ImportesCompra::redondear(bcdiv(bcmul($importe, $parte, 6), $total, 8));
    }

    /** @return numeric-string */
    public static function utilizado(PedidoDetalle $detalle, bool $bloquear = false): string
    {
        $query = CobroDetalle::where('pedido_detalle_id', $detalle->id)->whereHas('cobro', fn ($q) => $q->where('estado', 'confirmado'));
        if ($bloquear) {
            $query->lockForUpdate();
        }
        $usado = '0.00';
        foreach ($query->get() as $fila) {
            $usado = bcadd($usado, $fila->descuento_producto, 2);
        }

        return $usado;
    }

    /**
     * @param  numeric-string  $cantidad
     * @return numeric-string
     */
    public static function paraCantidad(PedidoDetalle $detalle, string $cantidad, bool $bloquear = false): string
    {
        $tiene = $detalle->es_cortesia || $detalle->descuento_tipo !== null || bccomp($detalle->descuento_importe, '0', 2) !== 0 || bccomp($detalle->descuento_valor, '0', 4) !== 0;
        if (! $tiene) {
            return '0.00';
        }
        if (! $detalle->autorizado_por || ! $detalle->motivo_beneficio_id || ! $detalle->beneficio_autorizado_at) {
            throw ValidationException::withMessages(['seguridad' => 'El beneficio de '.$detalle->descripcion.' necesita una autorización válida.']);
        }
        $pendiente = bcsub(bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4), $detalle->cantidad_cobrada, 4);
        $restante = bcsub($detalle->descuento_importe, self::utilizado($detalle, $bloquear), 2);
        $base = bcmul($pendiente, $detalle->precio_unitario, 2);
        if (bccomp($restante, '0', 2) < 0 || bccomp($restante, $base, 2) > 0) {
            throw ValidationException::withMessages(['seguridad' => 'El presupuesto del beneficio no coincide con las cantidades pendientes.']);
        }

        return self::repartir($restante, $cantidad, $pendiente);
    }

    /** @return array<string,string|int|null> */
    public static function instantanea(PedidoDetalle $detalle): array
    {
        return ['tipo' => $detalle->es_cortesia ? 'cortesia' : ($detalle->descuento_tipo ?? 'ninguno'), 'valor' => $detalle->descuento_valor,
            'motivo' => $detalle->beneficio_motivo_nombre, 'detalle' => $detalle->beneficio_motivo_texto, 'autorizado_por' => $detalle->autorizado_por, 'autorizado_nombre' => $detalle->beneficio_autorizado_nombre];
    }
}
