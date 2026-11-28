<?php

namespace App\Enums;

enum EstadoDevolucion: string
{
    case BORRADOR = 'borrador';
    case CONFIRMADA = 'confirmada';
    case ANULADA = 'anulada';
}
