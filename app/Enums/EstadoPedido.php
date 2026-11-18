<?php

namespace App\Enums;

enum EstadoPedido: string
{
    case ABIERTO = 'abierto';
    case EN_PREPARACION = 'en_preparacion';
    case LISTO = 'listo';
    case ENTREGADO = 'entregado';
    case CERRADO = 'cerrado';
    case ANULADO = 'anulado';
}
