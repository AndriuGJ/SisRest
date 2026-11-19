<?php

namespace App\Enums;

enum TipoTrabajoImpresion: string
{
    case COMANDA = 'comanda';
    case PRECUENTA = 'precuenta';
    case TICKET = 'ticket';
    case COMPROBANTE = 'comprobante';
}
