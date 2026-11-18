<?php

namespace App\Enums;

enum EstadoComprobanteEnvio: string
{
    case PENDIENTE = 'pendiente';
    case EN_PROCESO = 'en_proceso';
    case ENVIADO = 'enviado';
    case ACEPTADO = 'aceptado';
    case OBSERVADO = 'observado';
    case RECHAZADO = 'rechazado';
    case ERROR_CONEXION = 'error_conexion';
    case RESULTADO_INCIERTO = 'resultado_incierto';
}
