<?php

namespace App\Enums;

enum ModalidadPedido: string
{
    case MESA = 'mesa';
    case RAPIDA = 'rapida';
    case DELIVERY = 'delivery';
}
