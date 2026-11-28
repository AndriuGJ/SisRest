<?php

namespace App\Enums;

enum TipoMovimientoInventario: string
{
    case COMPRA = 'compra';
    case CONSUMO_PREPARACION = 'consumo_preparacion';
    case PRODUCCION = 'produccion';
    case ENTREGA = 'entrega';
    case REINGRESO = 'reingreso';
    case MERMA = 'merma';
    case CONSUMO_INTERNO = 'consumo_interno';
    case AJUSTE = 'ajuste';
}
