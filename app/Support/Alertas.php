<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Alertas
{
    /** @return array{text: string, type: string, toast: bool} */
    public static function mensaje(string $texto, string $tipo = 'success', bool $toast = true): array
    {
        return [
            'text' => $texto,
            'type' => in_array($tipo, ['success', 'error', 'warning', 'info', 'question'], true) ? $tipo : 'info',
            'toast' => $toast,
        ];
    }

    public static function flash(string $texto, string $tipo = 'success', bool $toast = true): void
    {
        session()->flash('alerta', ['id' => (string) Str::uuid(), ...self::mensaje($texto, $tipo, $toast)]);
    }
}
