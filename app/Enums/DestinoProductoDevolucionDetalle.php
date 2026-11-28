<?php

namespace App\Enums;

enum DestinoProductoDevolucionDetalle: string
{
    case SIN_REINGRESO = 'sin_reingreso';
    case REINGRESO = 'reingreso';
    case MERMA = 'merma';
}
