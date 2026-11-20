<?php

namespace App\Services\Pos;

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class AccesoPos
{
    public static function usuario(User $actor): User
    {
        $actual = User::findOrFail($actor->id);
        abort_unless($actual->activo, 403);
        Gate::forUser($actual)->authorize('pos.ver');
        abort_unless($actual->can('pedidos.ver_todos') || $actual->can('pedidos.ver_propios'), 403);

        return $actual;
    }

    public static function puedeVer(User $actor, Pedido $pedido): bool
    {
        return $actor->can('pedidos.ver_todos') || ($actor->can('pedidos.ver_propios') && ($pedido->mozo_id === $actor->id || $pedido->creado_por === $actor->id));
    }

    public static function pedido(User $actor, Pedido $pedido): void
    {
        abort_unless(self::puedeVer(self::usuario($actor), $pedido), 403);
    }

    /** @return Builder<Pedido> */
    public static function consulta(User $actor): Builder
    {
        $actual = self::usuario($actor);

        return Pedido::query()->when(! $actual->can('pedidos.ver_todos'), fn ($q) => $q->where(fn ($q) => $q->where('mozo_id', $actual->id)->orWhere('creado_por', $actual->id)));
    }
}
