<?php

namespace App\Services\Impresion;

use App\Models\Caja;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\Pedido;
use App\Models\TrabajoImpresion;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Facturacion\AccesoFacturacion;
use App\Services\Pos\AccesoPos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class AccesoImpresion
{
    public static function permiso(string $tipo): string
    {
        return match ($tipo) {
            'comanda', 'anulacion', 'cancelacion_pagada' => 'impresion.comandas',
            'precuenta' => 'impresion.precuentas',
            'ticket', 'comprobante' => 'impresion.tickets',
            default => abort(404),
        };
    }

    public static function pedido(User $actor, Pedido $pedido, string $tipo): User
    {
        $actual = AccesoPos::usuario($actor);
        AccesoPos::pedido($actual, $pedido);
        Gate::forUser($actual)->authorize(self::permiso($tipo));

        return $actual;
    }

    public static function cobro(User $actor, Cobro $cobro): User
    {
        $actual = self::pedido($actor, $cobro->pedido, 'ticket');
        Gate::forUser($actual)->authorize('cobros.crear');
        AccesoCajas::turno($actual, $cobro->turnoCaja);

        return $actual;
    }

    /** @return Builder<Caja> */
    public static function cajasTicket(User $actor): Builder
    {
        $actual = AccesoCajas::usuario($actor);

        return Caja::where('activo', true)->when(! $actual->can('cajas.ver_todas'), fn ($q) => $q->whereHas('turnos', fn ($t) => $t->where('usuario_id', $actual->id)));
    }

    public static function trabajo(User $actor, TrabajoImpresion $trabajo): void
    {
        abort_unless($trabajo->solicitud && $trabajo->pedido_id, 404);
        $tipo = $trabajo->solicitud['tipo'];
        self::pedido($actor, Pedido::findOrFail($trabajo->pedido_id), $tipo);
        if ($tipo === 'comprobante') {
            AccesoFacturacion::cobro($actor, Comprobante::findOrFail($trabajo->solicitud['id'])->cobro()->firstOrFail());
        }
        if ($tipo === 'ticket') {
            self::cobro($actor, Cobro::findOrFail($trabajo->solicitud['id']));
        }
    }
}
