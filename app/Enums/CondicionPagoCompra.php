<?php

namespace App\Enums;

enum CondicionPagoCompra: string
{
    case CONTADO = 'contado';
    case CREDITO = 'credito';
}
