<?php

namespace App\Services\Pos;

use App\Models\User;

class AnularPedido
{
    /** @return array{huella:string,detalles:list<array{id:int,cantidad:string}>,mermas:int} */
    public function previsualizar(User $actor, int $pedidoId, string $motivo): array
    {
        return app(GestionAnulaciones::class)->previsualizarPedido($actor, $pedidoId, $motivo);
    }

    public function anular(User $actor, int $pedidoId, string $motivo, string $huella, int|string $motivoId = '', #[\SensitiveParameter] string $claveAutorizacion = ''): void
    {
        app(GestionAnulaciones::class)->anularPedido($actor, $pedidoId, $motivo, $huella, $motivoId, $claveAutorizacion);
    }
}
