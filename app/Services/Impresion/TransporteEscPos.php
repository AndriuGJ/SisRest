<?php

namespace App\Services\Impresion;

use App\Exceptions\FalloImpresion;

class TransporteEscPos
{
    public function enviar(string $host, int $puerto, string $contenido): void
    {
        // Solo direcciones IPv4 literales: nunca URLs ni wrappers de PHP.
        if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $puerto < 1 || $puerto > 65535) {
            throw new FalloImpresion('La dirección IP o el puerto no son válidos.', false);
        }
        $socket = @stream_socket_client('tcp://'.$host.':'.$puerto, $errno, $error, 3, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            throw new FalloImpresion('No se pudo conectar con la impresora. Revisa la IP, el puerto y la conexión desde el servidor.', false);
        }
        try {
            stream_set_timeout($socket, 3);
            $limite = microtime(true) + 5;
            $posicion = 0;
            $longitud = strlen($contenido);
            while ($posicion < $longitud) {
                $escrito = @fwrite($socket, substr($contenido, $posicion));
                if ($escrito === false || $escrito === 0 || stream_get_meta_data($socket)['timed_out'] || microtime(true) > $limite) {
                    throw new FalloImpresion('El envío se interrumpió y pudo llegar parcialmente. Revisa el papel antes de solicitar un nuevo envío.', true);
                }
                $posicion += $escrito;
            }
            if (! @fflush($socket)) {
                throw new FalloImpresion('No se pudo confirmar el envío completo. Revisa el papel antes de solicitar un nuevo envío.', true);
            }
        } finally {
            fclose($socket);
        }
    }
}
