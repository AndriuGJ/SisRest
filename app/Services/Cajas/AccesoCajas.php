<?php

namespace App\Services\Cajas;

use App\Models\TurnoCaja;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class AccesoCajas
{
    public static function usuario(User $actor): User
    {
        $actual = User::findOrFail($actor->id);
        abort_unless($actual->activo && ($actual->can('cajas.ver_todas') || $actual->can('cajas.ver_propias')), 403);

        return $actual;
    }

    public static function turno(User $actor, TurnoCaja $turno): void
    {
        $actual = self::usuario($actor);
        abort_unless($actual->can('cajas.ver_todas') || $turno->usuario_id === $actual->id, 403);
    }

    /** @return Builder<TurnoCaja> */
    public static function consulta(User $actor): Builder
    {
        $actual = self::usuario($actor);

        return TurnoCaja::query()->when(! $actual->can('cajas.ver_todas'), fn ($q) => $q->where('usuario_id', $actual->id));
    }
}
