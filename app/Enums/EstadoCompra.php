<?php

namespace App\Enums;

enum EstadoCompra: string
{
    case BORRADOR = 'borrador';
    case CONFIRMADA = 'confirmada';
    case ANULADA = 'anulada';
}
