<?php

namespace App\Support;

final class Cantidad
{
    public static function mostrar(string $valor): string
    {
        return str_contains($valor, '.') ? rtrim(rtrim($valor, '0'), '.') : $valor;
    }
}
