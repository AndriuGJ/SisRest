<?php

namespace App\Services\Facturacion;

use ErrorException;
use RuntimeException;
use SoapFault;
use Throwable;

/** Only catalogued, non-sensitive diagnostics may cross the transport boundary. */
final class FalloSunat extends RuntimeException
{
    private const CATALOGO = [
        'conexion_dns' => ['conexion', 'No se pudo resolver el servidor remoto.'],
        'conexion_tls' => ['conexion', 'Falló la verificación o negociación TLS. No se deshabilitó la seguridad.'],
        'conexion_timeout' => ['conexion', 'Se agotó el tiempo de espera; la recepción del documento no está confirmada.'],
        'conexion_red' => ['conexion', 'No se pudo completar la conexión HTTP con el servicio.'],
        'soap_autenticacion' => ['autenticacion_soap', 'El servicio devolvió un fallo de autenticación o autorización SOAP.'],
        'soap_sunat' => ['respuesta_sunat', 'El servicio devolvió un SOAP Fault; no equivale a un CDR de aceptación o rechazo.'],
        'respuesta_invalida' => ['respuesta_sunat', 'El servicio respondió sin la estructura esperada de CDR, ticket o estado.'],
        'zip_preparacion' => ['local', 'No se pudo preparar o leer el ZIP de envío.'],
        'cdr_tamano' => ['cdr_local', 'El CDR supera el límite permitido.'],
        'cdr_archivo' => ['cdr_local', 'No se pudo escribir o leer el archivo temporal del CDR.'],
        'cdr_zip' => ['cdr_local', 'El CDR recibido no es un ZIP válido.'],
        'cdr_nombre' => ['cdr_local', 'El ZIP no contiene únicamente el XML esperado o excede el tamaño permitido.'],
        'cdr_xml' => ['cdr_local', 'El XML del CDR es inválido o contiene declaraciones no permitidas.'],
        'cdr_referencia' => ['cdr_local', 'El CDR no corresponde al emisor o documento enviado.'],
        'cdr_codigo' => ['cdr_local', 'El CDR contiene un código de respuesta inválido.'],
        'cdr_guardado' => ['local', 'No se pudo conservar el CDR en el almacenamiento privado.'],
        'error_no_clasificado' => ['local_no_clasificado', 'Ocurrió un error no clasificado. Revise la etapa y la referencia de diagnóstico.'],
    ];

    public function __construct(public readonly string $tipo, public readonly ?string $codigoSunat = null)
    {
        if (! isset(self::CATALOGO[$tipo]) || ($codigoSunat !== null && ! preg_match('/^\d{1,5}$/D', $codigoSunat))) {
            throw new \InvalidArgumentException('Diagnóstico fiscal inválido.');
        }
        parent::__construct(self::CATALOGO[$tipo][1]);
    }

    public static function desde(#[\SensitiveParameter] Throwable $error): self
    {
        if ($error instanceof self) {
            return $error;
        }
        if (! $error instanceof SoapFault && ! $error instanceof ErrorException) {
            return new self('error_no_clasificado');
        }
        // Never extract arbitrary digits from faultstring/detail (could contain credentials).
        $fault = $error instanceof SoapFault ? (string) $error->faultcode : '';
        $codigo = preg_match('/^(?:(?:[a-zA-Z][\w-]*:)?(?:Client|Server)\.)?(\d{1,5})$/D', $fault, $m) ? $m[1] : null;
        if (($codigo !== null && in_array(str_pad($codigo, 4, '0', STR_PAD_LEFT), ['0101', '0102', '0103', '0104', '0105', '0106', '0111', '0112', '0113'], true)) || preg_match('/^(?:[\w-]+:)?(?:FailedAuthentication|InvalidSecurity|InvalidSecurityToken)$/D', $fault)) {
            return new self('soap_autenticacion', $codigo);
        }
        if ($codigo !== null) {
            return new self('soap_sunat', $codigo);
        }
        // Pattern matching is only a hint; original text/trace is never retained.
        $texto = strtolower($error->getMessage());
        foreach ([
            'conexion_dns' => '/getaddrinfo|php_network_getaddresses|name or service not known|could not resolve|temporary failure in name resolution/',
            'conexion_tls' => '/certificate verify failed|ssl operation failed|tls handshake|failed to enable crypto|ssl handshake/',
            'conexion_timeout' => '/timed out|timeout|time out/',
            'conexion_red' => '/could not connect|connection refused|connection reset|error fetching http headers|failed to connect|network is unreachable/',
        ] as $tipo => $patron) {
            if (preg_match($patron, $texto)) {
                return new self($tipo);
            }
        }

        return new self($error instanceof SoapFault ? 'soap_sunat' : 'error_no_clasificado');
    }

    /** @return array{categoria:string,tipo:string,codigo_sunat:?string,mensaje:string} */
    public function seguro(): array
    {
        return ['categoria' => self::CATALOGO[$this->tipo][0], 'tipo' => $this->tipo, 'codigo_sunat' => $this->codigoSunat, 'mensaje' => $this->getMessage()];
    }
}
