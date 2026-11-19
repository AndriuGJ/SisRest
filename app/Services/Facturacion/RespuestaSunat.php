<?php

namespace App\Services\Facturacion;

final readonly class RespuestaSunat
{
    public function __construct(public ?string $ticket = null, public ?string $cdr = null, public bool $pendiente = false, public ?string $codigo = null) {}
}
