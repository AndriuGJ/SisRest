<?php

namespace App\Enums;

enum TipoProducto: string
{
    case PLATO = 'plato';
    case BEBIDA = 'bebida';
    case REVENTA = 'reventa';
    case COMBO = 'combo';
    case ADICIONAL = 'adicional';
    case MENU = 'menu';
}
