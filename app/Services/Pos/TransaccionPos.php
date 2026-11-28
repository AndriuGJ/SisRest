<?php

namespace App\Services\Pos;

use App\Models\Caja;
use App\Models\Mesa;
use App\Models\Salon;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Inventario\TransaccionCatalogo;
use Closure;
use Illuminate\Validation\ValidationException;

trait TransaccionPos
{
    use TransaccionCatalogo;

    /**
     * @template T
     *
     * @param  Closure():T  $operacion
     * @return T
     */
    private function ejecutar(User $actor, string $permiso, Closure $operacion): mixed
    {
        return $this->transaccion($actor, $permiso, function () use ($operacion) {
            Salon::orderBy('id')->lockForUpdate()->get();
            Mesa::orderBy('id')->lockForUpdate()->get();
            Caja::orderBy('id')->lockForUpdate()->get();

            return $operacion();
        });
    }

    private function turno(int $id): TurnoCaja
    {
        $turno = TurnoCaja::lockForUpdate()->findOrFail($id);
        // La caja ya fue bloqueada antes del turno; esta es una relectura actual.
        if ($turno->estado->value !== 'abierta' || ! Caja::lockForUpdate()->findOrFail($turno->caja_id)->activo) {
            throw ValidationException::withMessages(['turnoId' => 'Selecciona una caja abierta para operar el pedido.']);
        }

        return $turno;
    }
}
