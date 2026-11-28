<?php

namespace App\Enums;

enum OrigenFondosPagoCompra: string
{
    case CAJA = 'caja';
    case EMPRESA = 'empresa';
    case DUENO = 'dueno';
}
