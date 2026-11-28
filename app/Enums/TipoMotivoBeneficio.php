<?php

namespace App\Enums;

enum TipoMotivoBeneficio: string
{
    case DESCUENTO = 'descuento';
    case CORTESIA = 'cortesia';
    case AMBOS = 'ambos';
}
