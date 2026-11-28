<?php

namespace App\Enums;

enum EstadoPreparacion: string
{
    case BORRADOR = 'borrador';
    case CONFIRMADA = 'confirmada';
    case ANULADA = 'anulada';
}
