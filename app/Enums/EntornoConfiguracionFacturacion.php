<?php

namespace App\Enums;

enum EntornoConfiguracionFacturacion: string
{
    case BETA = 'beta';
    case PRODUCCION = 'produccion';
}
