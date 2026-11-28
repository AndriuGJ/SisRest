<?php

namespace App\Enums;

enum EstadoComprobante: string
{
    case EMITIDO = 'emitido';
    case ANULADO = 'anulado';
}
