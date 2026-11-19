<?php

namespace App\Services\Devoluciones;

use App\Models\Cobro;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type LineaSaldo array{id:int,pedidoDetalleId:int,descripcion:string,cantidad:numeric-string,devuelta:numeric-string,disponible:numeric-string,importe:numeric-string}
 * @phpstan-type PagoSaldo array{id:int,medioId:int,nombre:string,efectivo:bool,requiereReferencia:bool,disponible:numeric-string}
 * @phpstan-type Saldo array{lineas:array<int,LineaSaldo>,pagos:array<int,PagoSaldo>,envio:numeric-string,devuelto:numeric-string,disponible:numeric-string,huella:string}
 */
final class SaldoDevolucion
{
    /** @return Saldo */
    public static function calcular(Cobro $cobro, bool $bloquear = false): array
    {
        $detallesQ = $cobro->detalles()->orderBy('id');
        $pagosQ = $cobro->pagos()->with('medioPago')->orderBy('id');
        $devolucionesQ = $cobro->devoluciones()->with(['detalles', 'pagos'])->orderBy('id');
        if ($bloquear) {
            $detallesQ->lockForUpdate();
            $pagosQ->lockForUpdate();
            $devolucionesQ->lockForUpdate();
        }
        $detalles = $detallesQ->get();
        $pagos = $pagosQ->get();
        $devoluciones = $devolucionesQ->get();
        $lineas = $medios = [];
        $base = $cobro->cargo_envio;
        $abonado = '0.00';
        foreach ($detalles as $d) {
            $lineas[$d->id] = ['id' => $d->id, 'pedidoDetalleId' => $d->pedido_detalle_id, 'descripcion' => $d->descripcion, 'cantidad' => $d->cantidad, 'devuelta' => '0.0000', 'disponible' => $d->cantidad, 'importe' => $d->total];
            $base = bcadd($base, $d->total, 2);
        }
        foreach ($pagos as $p) {
            $medios[$p->id] = ['id' => $p->id, 'medioId' => $p->medio_pago_id, 'nombre' => $p->medio_pago_nombre ?? $p->medioPago->nombre, 'efectivo' => $p->afecta_efectivo ?? $p->medioPago->afecta_efectivo, 'requiereReferencia' => $p->medioPago->requiere_referencia, 'disponible' => $p->monto];
            $abonado = bcadd($abonado, $p->monto, 2);
        }
        if (bccomp($base, $cobro->total, 2) !== 0 || bccomp($abonado, $base, 2) !== 0) {
            throw ValidationException::withMessages(['seguridad' => 'El historial del cobro no cuadra con sus productos y pagos.']);
        }
        $devuelto = '0.00';
        $envio = $cobro->cargo_envio;
        foreach ($devoluciones as $devolucion) {
            if ($devolucion->estado->value === 'anulada') {
                continue;
            }
            if ($devolucion->estado->value !== 'confirmada') {
                throw ValidationException::withMessages(['seguridad' => 'Resuelve la devolución en borrador antes de registrar otra.']);
            }
            $importe = $devolucion->cargo_envio;
            $reintegrado = '0.00';
            foreach ($devolucion->detalles as $d) {
                $id = $d->cobro_detalle_id;
                if (! isset($lineas[$id])) {
                    throw ValidationException::withMessages(['seguridad' => 'Una devolución no corresponde a las líneas originales.']);
                }
                $lineas[$id]['devuelta'] = bcadd($lineas[$id]['devuelta'], $d->cantidad, 4);
                $lineas[$id]['disponible'] = bcsub($lineas[$id]['disponible'], $d->cantidad, 4);
                $lineas[$id]['importe'] = bcsub($lineas[$id]['importe'], $d->importe, 2);
                $importe = bcadd($importe, $d->importe, 2);
                if (bccomp($lineas[$id]['disponible'], '0', 4) < 0 || bccomp($lineas[$id]['importe'], '0', 2) < 0) {
                    throw ValidationException::withMessages(['seguridad' => 'Las devoluciones previas superan el cobro original.']);
                }
            }
            foreach ($devolucion->pagos as $p) {
                if (! $p->pago_original_id || ! isset($medios[$p->pago_original_id]) || $medios[$p->pago_original_id]['medioId'] !== $p->medio_pago_id) {
                    throw ValidationException::withMessages(['seguridad' => 'Falta conciliar el pago original de una devolución anterior.']);
                }
                $medios[$p->pago_original_id]['disponible'] = bcsub($medios[$p->pago_original_id]['disponible'], $p->monto, 2);
                $reintegrado = bcadd($reintegrado, $p->monto, 2);
                if (bccomp($medios[$p->pago_original_id]['disponible'], '0', 2) < 0) {
                    throw ValidationException::withMessages(['seguridad' => 'Las devoluciones de un medio superan el importe aplicado.']);
                }
            }
            $envio = bcsub($envio, $devolucion->cargo_envio, 2);
            if (bccomp($importe, $devolucion->total, 2) !== 0 || bccomp($importe, $reintegrado, 2) !== 0 || bccomp($envio, '0', 2) < 0) {
                throw ValidationException::withMessages(['seguridad' => 'El historial de devoluciones requiere conciliación.']);
            }
            $devuelto = bcadd($devuelto, $importe, 2);
        }

        return ['lineas' => $lineas, 'pagos' => $medios, 'envio' => $envio, 'devuelto' => $devuelto, 'disponible' => bcsub($cobro->total, $devuelto, 2),
            'huella' => hash('sha256', json_encode([$cobro->getAttributes(), $detalles->toArray(), $pagos->toArray(), $devoluciones->toArray()], JSON_THROW_ON_ERROR))];
    }
}
