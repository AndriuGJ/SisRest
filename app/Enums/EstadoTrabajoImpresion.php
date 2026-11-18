<?php

namespace App\Enums;

enum EstadoTrabajoImpresion: string
{
    case PENDIENTE = 'pendiente';
    case PROCESANDO = 'procesando';
    case ENVIADO = 'enviado';
    case ERROR = 'error';
    case RESULTADO_INCIERTO = 'resultado_incierto';
}
