<?php

namespace App\Services\Compras;

use Illuminate\Validation\ValidationException;

final class ImportesCompra
{
    /** @return numeric-string */
    public static function importe(string $valor, string $campo, int $decimales = 2, bool $positivo = false): string
    {
        if (! preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,'.$decimales.'})?$/', $valor) || ! is_numeric($valor)
            || ($positivo && bccomp($valor, '0', $decimales) <= 0)) {
            throw ValidationException::withMessages([$campo => 'Usa un importe '.($positivo ? 'positivo' : 'no negativo').' con hasta '.$decimales.' decimales.']);
        }

        return bcadd($valor, '0', $decimales);
    }

    /**
     * @param  numeric-string  $valor
     * @return numeric-string
     */
    public static function redondear(string $valor, string $campo = 'seguridad'): string
    {
        $resultado = bcadd($valor, '0.005', 2);
        if (bccomp($resultado, '999999999999.99', 2) > 0 || bccomp($resultado, '0', 2) < 0) {
            throw ValidationException::withMessages([$campo => 'El importe supera el rango admitido.']);
        }

        return $resultado;
    }
}
