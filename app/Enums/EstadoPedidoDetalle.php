<?php

namespace App\Enums;

enum EstadoPedidoDetalle: string
{
    case BORRADOR = 'borrador';
    case CONFIRMADO = 'confirmado';
    case ANULADO = 'anulado';
}
