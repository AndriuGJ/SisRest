<?php

namespace App\Enums;

enum TipoArticulo: string
{
    case INSUMO = 'insumo';
    case PREPARADO = 'preparado';
    case REVENTA = 'reventa';
}
