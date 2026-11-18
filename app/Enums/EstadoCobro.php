<?php

namespace App\Enums;

enum EstadoCobro: string
{
    case BORRADOR = 'borrador';
    case CONFIRMADO = 'confirmado';
    case ANULADO = 'anulado';
}
