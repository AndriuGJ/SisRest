<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Facturacion\EnviarComprobante;
use App\Services\Facturacion\FalloSunat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Only IDs enter the queue. The service's durable reservation prevents a repeated send. */
class EnviarComprobanteSunat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $comprobanteId, public int $usuarioId) {}

    public function handle(EnviarComprobante $servicio): void
    {
        try {
            $servicio->enviar(User::findOrFail($this->usuarioId), $this->comprobanteId);
        } catch (Throwable $error) {
            // The queue's failed_jobs must never serialize a raw SOAP error or credentials.
            throw FalloSunat::desde($error);
        }
    }
}
