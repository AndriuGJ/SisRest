<?php

namespace App\Services\Facturacion;

use App\Models\Cobro;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Pos\AccesoPos;
use Illuminate\Support\Facades\Gate;

final class AccesoFacturacion
{
    public static function usuario(User $actor, string $permiso = 'comprobantes.ver'): User
    {
        $actual = User::findOrFail($actor->id);
        abort_unless($actual->activo, 403);
        Gate::forUser($actual)->authorize('comprobantes.ver');
        Gate::forUser($actual)->authorize($permiso);

        return $actual;
    }

    public static function cobro(User $actor, Cobro $cobro, string $permiso = 'comprobantes.ver'): void
    {
        $actual = self::usuario($actor, $permiso);
        AccesoPos::pedido($actual, $cobro->pedido);
        AccesoCajas::turno($actual, $cobro->turnoCaja);
    }
}
