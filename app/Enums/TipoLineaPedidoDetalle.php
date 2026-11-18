<?php

namespace App\Enums;

enum TipoLineaPedidoDetalle: string
{
    case PRODUCTO = 'producto';
    case COMPONENTE = 'componente';
    case ADICIONAL = 'adicional';
}
