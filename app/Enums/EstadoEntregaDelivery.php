<?php

namespace App\Enums;

enum EstadoEntregaDelivery: string
{
    case PENDIENTE = 'pendiente';
    case EN_PREPARACION = 'en_preparacion';
    case LISTO = 'listo';
    case EN_CAMINO = 'en_camino';
    case ENTREGADO = 'entregado';
    case CANCELADO = 'cancelado';
}
