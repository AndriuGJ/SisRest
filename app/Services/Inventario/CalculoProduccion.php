<?php

namespace App\Services\Inventario;

use Illuminate\Validation\ValidationException;

final class CalculoProduccion
{
    /** @return numeric-string */
    public static function cantidad(string $valor, string $campo = 'cantidad', bool $positiva = true): string
    {
        if (! preg_match(Presentaciones::DECIMAL, $valor) || ! is_numeric($valor)
            || ($positiva && bccomp($valor, '0', 4) <= 0)) {
            throw ValidationException::withMessages([$campo => 'Usa una cantidad '.($positiva ? 'positiva' : 'no negativa').' con hasta cuatro decimales.']);
        }

        return bcadd($valor, '0', 4);
    }

    /**
     * @param  numeric-string  $ingrediente
     * @param  numeric-string  $producida
     * @param  numeric-string  $rendimiento
     * @return numeric-string
     */
    public static function escalar(string $ingrediente, string $producida, string $rendimiento): string
    {
        $numerador = bcmul($ingrediente, $producida, 8);
        $resultado = bcdiv($numerador, $rendimiento, 4);
        if (bccomp(bcmul($resultado, $rendimiento, 8), $numerador, 8) < 0) {
            $resultado = bcadd($resultado, '0.0001', 4);
        }
        if (bccomp($resultado, '999999999999.9999', 4) > 0) {
            throw ValidationException::withMessages(['cantidad' => 'La preparación supera el máximo de cantidades admitido.']);
        }

        return $resultado;
    }

    /**
     * @param  numeric-string  $valor
     * @return numeric-string
     */
    public static function costo(string $valor): string
    {
        $costo = bcadd($valor, '0.0000005', 6);
        if (bccomp($costo, '0', 6) < 0 || bccomp($costo, '999999999999.999999', 6) > 0) {
            throw ValidationException::withMessages(['seguridad' => 'El costo supera el rango admitido para esta operación.']);
        }

        return $costo;
    }
}
