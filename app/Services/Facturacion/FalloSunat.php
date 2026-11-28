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
        'credenciales_incompletas' => ['local', 'Configura el usuario y la clave SOL del entorno seleccionado. No se realizó la operación SOAP.'],
        'usuario_sol_formato' => ['local', 'Ingresa solamente el usuario SOL, sin anteponer el RUC. No se realizó la operación SOAP.'],
        'conexion_dns' => ['conexion', 'No se pudo resolver el servidor remoto.'],
        'conexion_tls' => ['conexion', 'Falló la verificación o negociación TLS. No se deshabilitó la seguridad.'],
        'conexion_timeout' => ['conexion', 'Se agotó el tiempo de espera; la recepción del documento no está confirmada.'],
        'conexion_red' => ['conexion', 'No se pudo completar la conexión HTTP con el servicio.'],
        'http_servidor' => ['respuesta_http', 'El transporte informó un error HTTP del servidor remoto o de un intermediario. El estado del ticket no está confirmado.'],
        'http_cliente' => ['respuesta_http', 'El transporte informó un error HTTP de acceso o solicitud. No demuestra por sí solo un error de clave SOL.'],
        'http_sin_cabeceras' => ['transporte_http', 'No se recibieron cabeceras HTTP válidas. Puede ser una espera agotada o un cierre del servicio o intermediario; no hay un estado HTTP confirmado.'],
        'http_no_clasificado' => ['transporte_http', 'El cliente SOAP informó un fallo HTTP sin un estado reconocible. No se puede atribuir su origen con certeza.'],
        'soap_decodificacion' => ['respuesta_no_verificable', 'El cliente SOAP no pudo interpretar la respuesta como XML SOAP válido. No se confirma el resultado del ticket.'],
        'soap_configuracion_local' => ['local', 'No se pudo configurar de forma temporal el tiempo de espera del cliente SOAP. No se realizó la operación.'],
        'soap_wsdl_local' => ['local', 'No se pudo cargar o interpretar la definición WSDL del cliente SOAP.'],
        'entorno_inconsistente' => ['local', 'La configuración actual no corresponde al entorno del comprobante. No se contactó al servicio.'],
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
        'cdr_no_definitivo' => ['respuesta_no_verificable', 'El CDR no expresa un resultado fiscal definitivo. Se conserva el estado anterior del resumen.'],
        'cdr_codigo' => ['cdr_local', 'El CDR contiene un código de respuesta inválido.'],
        'cdr_guardado' => ['local', 'No se pudo conservar el CDR en el almacenamiento privado.'],
        'error_no_clasificado' => ['local_no_clasificado', 'Ocurrió un error no clasificado. Revise la etapa y la referencia de diagnóstico.'],
    ];

    public function __construct(public readonly string $tipo, public readonly ?string $codigoSunat = null, public readonly ?int $estadoHttp = null)
    {
        if (($estadoHttp !== null && ($estadoHttp < 400 || $estadoHttp > 599)) || ! isset(self::CATALOGO[$tipo]) || ($codigoSunat !== null && ! preg_match('/^\d{1,5}$/D', $codigoSunat))) {
            throw new \InvalidArgumentException('Diagnóstico fiscal inválido.');
        }
        parent::__construct($tipo === 'soap_autenticacion' && str_pad($codigoSunat ?? '', 4, '0', STR_PAD_LEFT) === '0111' ? 'SUNAT devolvió 0111: revisa la autorización del emisor y del usuario SOL para este servicio.' : self::CATALOGO[$tipo][1]);
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
        // HTTP errors from the native client are not SOAP fault codes. Never scrape arbitrary numbers.
        $texto = strtolower($error->getMessage());
        if (strcasecmp($fault, 'WSDL') === 0 || ($error instanceof ErrorException && str_starts_with($texto, 'soap-error: parsing wsdl:'))) {
            return new self('soap_wsdl_local');
        }
        if (preg_match('/^(?:[\w-]+:)?Client$/D', $fault) && (str_starts_with($texto, 'looks like we got no xml document') || str_starts_with($texto, 'soap-error: encoding:'))) {
            return new self('soap_decodificacion');
        }
        // A real remote SOAP Server/Client fault must not be relabelled by words in faultstring.
        if (preg_match('/^(?:[\w-]+:)?(?:Client|Server|Sender|Receiver)$/D', $fault)) {
            return new self('soap_sunat');
        }
        if (strcasecmp($fault, 'HTTP') === 0) {
            foreach ([
                400 => 'bad request', 401 => ['unauthorized', 'authorization required'], 403 => 'forbidden', 404 => 'not found',
                405 => 'method not allowed', 408 => 'request timeout', 413 => 'payload too large',
                415 => 'unsupported media type', 429 => 'too many requests',
                500 => 'internal server error', 501 => 'not implemented', 502 => 'bad gateway',
                503 => 'service unavailable', 504 => ['gateway timeout', 'gateway time-out'],
            ] as $estado => $razon) {
                foreach ((array) $razon as $frase) {
                    if ($texto === $frase || str_starts_with($texto, $frase.' ')) {
                        return new self($estado >= 500 ? 'http_servidor' : 'http_cliente', estadoHttp: $estado);
                    }
                }
            }
        }
        // Network patterns are hints only: never retain the original text, SOAP detail or trace.

        foreach ([
            'conexion_dns' => '/getaddrinfo|php_network_getaddresses|name or service not known|could not resolve|temporary failure in name resolution/',
            'conexion_tls' => '/certificate verify failed|ssl operation failed|tls handshake|failed to enable crypto|ssl handshake/',
            'conexion_timeout' => '/timed out|timeout|time out/',
            'http_sin_cabeceras' => '/error fetching http headers/',
            'conexion_red' => '/could not connect|connection refused|connection reset|failed to connect|network is unreachable/',
        ] as $tipo => $patron) {
            if (preg_match($patron, $texto)) {
                return new self($tipo);
            }
        }

        return new self(strcasecmp($fault, 'HTTP') === 0 ? 'http_no_clasificado' : ($error instanceof SoapFault ? 'soap_sunat' : 'error_no_clasificado'));
    }

    /** @return array{categoria:string,tipo:string,codigo_sunat:?string,estado_http:?int,mensaje:string} */
    public function seguro(): array
    {
        return ['categoria' => self::CATALOGO[$this->tipo][0], 'tipo' => $this->tipo, 'codigo_sunat' => $this->codigoSunat, 'estado_http' => $this->estadoHttp, 'mensaje' => $this->getMessage()];
    }
}
