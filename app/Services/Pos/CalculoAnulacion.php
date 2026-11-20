<?php

namespace App\Services\Pos;

use App\Models\EnvioCocinaDetalle;
use Illuminate\Validation\ValidationException;

final class CalculoAnulacion
{
    /**
     * @param  numeric-string  $cantidad
     * @return array{merma:numeric-string,despues:array{cantidad_anulada:numeric-string,cantidad_iniciada:numeric-string,cantidad_lista:numeric-string}}
     */
    public static function envio(EnvioCocinaDetalle $envio, string $cantidad): array
    {
        $sinIniciar = bcsub(bcsub($envio->cantidad, $envio->cantidad_anulada, 4), $envio->cantidad_iniciada, 4);
        $libre = bccomp($cantidad, $sinIniciar, 4) <= 0 ? $cantidad : $sinIniciar;
        $merma = bcsub($cantidad, $libre, 4);
        $preparando = bcsub($envio->cantidad_iniciada, $envio->cantidad_lista, 4);
        $proceso = bccomp($merma, $preparando, 4) <= 0 ? $merma : $preparando;
        $lista = bcsub($merma, $proceso, 4);
        $despues = ['cantidad_anulada' => bcadd($envio->cantidad_anulada, $cantidad, 4), 'cantidad_iniciada' => bcsub($envio->cantidad_iniciada, $merma, 4), 'cantidad_lista' => bcsub($envio->cantidad_lista, $lista, 4)];
        if (bccomp($despues['cantidad_lista'], $envio->cantidad_entregada, 4) < 0) {
            throw ValidationException::withMessages(['seguridad' => 'La cancelación alcanzaría cantidades entregadas.']);
        }

        return ['merma' => $merma, 'despues' => $despues];
    }
}
