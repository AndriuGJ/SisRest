<?php

namespace App\Services\Cajas;

use App\Models\Cobro;
use App\Models\Devolucion;
use App\Models\DevolucionPago;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Models\TurnoCaja;

class ResumenTurno
{
    /** @return array{fondo:numeric-string,ingresos:numeric-string,egresos:numeric-string,retiros:numeric-string,esperado:numeric-string,cobrado:numeric-string,devuelto:numeric-string,neto_cobrado:numeric-string,devoluciones_medios:array<int,array{nombre:string,monto:numeric-string}>,medios:array<int,array{nombre:string,monto:numeric-string}>,huella:string} */
    public function calcular(TurnoCaja $turno, bool $bloquear = false): array
    {
        $ingresos = $egresos = $retiros = $cobrado = '0.00';
        $movimientosQuery = MovimientoCaja::where('turno_caja_id', $turno->id)->orderBy('id');
        if ($bloquear) {
            $movimientosQuery->lockForUpdate();
        }
        $movimientos = $movimientosQuery->get();
        foreach ($movimientos as $movimiento) {
            match ($movimiento->tipo->value) {
                'ingreso' => $ingresos = bcadd($ingresos, $movimiento->monto, 2),
                'egreso' => $egresos = bcadd($egresos, $movimiento->monto, 2),
                'retiro' => $retiros = bcadd($retiros, $movimiento->monto, 2),
            };
        }
        $cobrosQuery = Cobro::where('turno_caja_id', $turno->id)->where('estado', 'confirmado')->orderBy('id');
        if ($bloquear) {
            $cobrosQuery->lockForUpdate();
        }
        $cobros = $cobrosQuery->pluck('id');
        $pagosQuery = Pago::with('medioPago')->whereIn('cobro_id', $cobros)->orderBy('id');
        if ($bloquear) {
            $pagosQuery->lockForUpdate();
        }
        $pagos = $pagosQuery->get();
        $medios = [];
        foreach ($pagos as $pago) {
            $id = $pago->medio_pago_id;
            $medios[$id] ??= ['nombre' => $pago->medio_pago_nombre ?? $pago->medioPago->nombre, 'monto' => '0.00'];
            $medios[$id]['monto'] = bcadd($medios[$id]['monto'], $pago->monto, 2);
            $cobrado = bcadd($cobrado, $pago->monto, 2);
        }
        $devolucionesQ = Devolucion::where('turno_caja_id', $turno->id)->where('estado', 'confirmada')->orderBy('id');
        if ($bloquear) {
            $devolucionesQ->lockForUpdate();
        }
        $devoluciones = $devolucionesQ->get();
        $reintegrosQ = DevolucionPago::with('medioPago')->whereIn('devolucion_id', $devoluciones->pluck('id'))->orderBy('id');
        if ($bloquear) {
            $reintegrosQ->lockForUpdate();
        }
        $reintegros = $reintegrosQ->get();
        $devuelto = '0.00';
        $devolucionesMedios = [];
        foreach ($reintegros as $pago) {
            $id = $pago->medio_pago_id;
            $devolucionesMedios[$id] ??= ['nombre' => $pago->medio_pago_nombre ?? $pago->medioPago->nombre, 'monto' => '0.00'];
            $devolucionesMedios[$id]['monto'] = bcadd($devolucionesMedios[$id]['monto'], $pago->monto, 2);
            $devuelto = bcadd($devuelto, $pago->monto, 2);
        }
        $esperado = bcsub(bcsub(bcadd($turno->fondo_inicial, $ingresos, 2), $egresos, 2), $retiros, 2);
        $huella = hash('sha256', json_encode([$turno->id, $turno->estado->value, $turno->fondo_inicial, $turno->usuario_id,
            $movimientos->map(fn (MovimientoCaja $m) => [$m->id, $m->tipo->value, $m->monto])->all(),
            $pagos->map(fn (Pago $p) => [$p->id, $p->medio_pago_id, $p->monto])->all(), $devoluciones->toArray(), $reintegros->toArray()], JSON_THROW_ON_ERROR));

        return ['fondo' => $turno->fondo_inicial, 'ingresos' => $ingresos, 'egresos' => $egresos, 'retiros' => $retiros,
            'devuelto' => $devuelto, 'neto_cobrado' => bcsub($cobrado, $devuelto, 2), 'devoluciones_medios' => $devolucionesMedios, 'esperado' => $esperado, 'cobrado' => $cobrado, 'medios' => $medios, 'huella' => $huella];
    }
}
