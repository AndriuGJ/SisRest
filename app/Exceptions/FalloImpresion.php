<?php

namespace App\Exceptions;

use RuntimeException;

class FalloImpresion extends RuntimeException
{
    public function __construct(string $mensaje, public readonly bool $incierto)
    {
        parent::__construct($mensaje);
    }
}
