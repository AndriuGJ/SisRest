<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;
use App\Models\ResumenDiario;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Pos\AccesoPos;
use Illuminate\Database\Eloquent\Builder;

final class AccesoResumenes
{
    /** @return Builder<Comprobante> */
    public static function comprobantes(User $actor): Builder
    {
        $actor = AccesoFacturacion::usuario($actor);

        return Comprobante::whereHas('cobro', fn ($q) => $q->whereIn('pedido_id', AccesoPos::consulta($actor)->select('id'))->whereIn('turno_caja_id', AccesoCajas::consulta($actor)->select('id')));
    }

    /** @return Builder<ResumenDiario> */
    public static function consulta(User $actor): Builder
    {
        $permitidos = self::comprobantes($actor)->select('comprobantes.id');

        return ResumenDiario::whereHas('detalles')->whereDoesntHave('detalles', fn ($q) => $q->whereNotIn('comprobante_id', $permitidos));
    }

    public static function autorizar(User $actor, ResumenDiario $r, string $permiso = 'comprobantes.ver'): void
    {
        AccesoFacturacion::usuario($actor, $permiso);
        $permitidos = self::comprobantes($actor)->select('comprobantes.id');
        abort_unless($r->detalles()->exists() && ! $r->detalles()->whereNotIn('comprobante_id', $permitidos)->exists(), 403);
    }
}
