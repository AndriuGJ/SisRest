<?php

namespace App\Enums;

enum EstadoSunatComprobante: string
{
    case PENDIENTE = 'pendiente';
    case ENVIADO = 'enviado';
    case ACEPTADO = 'aceptado';
    case OBSERVADO = 'observado';
    case RECHAZADO = 'rechazado';
    case DESCONOCIDO = 'desconocido';
}
