<?php

namespace App\Services\Devoluciones;

use App\Models\Cobro;
use App\Models\Devolucion;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Pos\AccesoPos;
use Illuminate\Support\Facades\Gate;

final class AccesoDevoluciones
{
    public static function usuario(User $actor): User
    {
        $actual = AccesoPos::usuario($actor);
        Gate::forUser($actual)->authorize('devoluciones.crear');
        AccesoCajas::usuario($actual);

        return $actual;
    }

    public static function cobro(User $actor, Cobro $cobro): void
    {
        $actual = self::usuario($actor);
        AccesoPos::pedido($actual, $cobro->pedido);
        AccesoCajas::turno($actual, $cobro->turnoCaja);
    }

    public static function devolucion(User $actor, Devolucion $devolucion): void
    {
        self::cobro($actor, $devolucion->cobro);
        AccesoCajas::turno($actor, $devolucion->turnoCaja);
    }
}
