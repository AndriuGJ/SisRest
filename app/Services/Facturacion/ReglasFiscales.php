<?php

namespace App\Services\Facturacion;

use Illuminate\Validation\ValidationException;

final class ReglasFiscales
{
    /** @var array<int, string> */
    public const AFECTACIONES = [
        '10' => 'Gravado', '20' => 'Exonerado', '30' => 'Inafecto',
        '11' => 'Gratuito: retiro por premio', '12' => 'Gratuito: donación',
        '13' => 'Gratuito: retiro', '14' => 'Gratuito: publicidad',
        '15' => 'Gratuito: bonificación', '16' => 'Gratuito: entrega a trabajadores',
        '21' => 'Gratuito: exonerado', '31' => 'Inafecto: bonificación',
        '32' => 'Inafecto: retiro', '35' => 'Inafecto: premio', '36' => 'Inafecto: publicidad',
    ];

    /** @phpstan-assert true $condicion */
    public static function exigir(bool $condicion, string $mensaje, string $campo = 'facturacion'): void
    {
        if (! $condicion) {
            throw ValidationException::withMessages([$campo => $mensaje]);
        }
    }

    public static function ruc(string $numero): bool
    {
        if (! preg_match('/^(10|15|16|17|20)\d{9}$/', $numero)) {
            return false;
        }
        $suma = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $factor) {
            $suma += (int) $numero[$i] * $factor;
        }

        return ((11 - $suma % 11) % 10) === (int) $numero[10];
    }

    /**
     * @param  numeric-string  $numero
     * @return numeric-string
     */
    public static function redondear(string $numero, int $decimales = 2): string
    {
        return bcadd($numero, bcdiv('0.5', bcpow('10', (string) $decimales, 0), $decimales + 1), $decimales);
    }

    public static function texto(string $texto): string
    {
        // Greenter uses CDATA fields: prevent terminator injection as well as XML control characters.
        return str_replace(']]>', ']] >', preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto) ?? '');
    }
}
