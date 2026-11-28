<?php

namespace App\Concerns;

use App\Support\Alertas;

trait ConAlertas
{
    protected function notificar(string $texto, string $tipo = 'success', bool $toast = true): void
    {
        $this->dispatch('app:alert', ...Alertas::mensaje($texto, $tipo, $toast));
    }

    protected function confirmar(string $titulo, string $texto, string $accion, string $boton = 'Confirmar', bool $peligro = false): void
    {
        $this->dispatch('app:confirm',
            title: $titulo,
            text: $texto,
            action: $accion,
            componentId: $this->getId(),
            confirmText: $boton,
            danger: $peligro,
        );
    }
}
