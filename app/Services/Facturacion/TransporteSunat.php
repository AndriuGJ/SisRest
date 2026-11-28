<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;
use App\Models\ConfiguracionFacturacion;
use Greenter\Ws\Services\SoapClient;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\Ws\Services\WsdlProvider;
use Throwable;
use ZipArchive;

class TransporteSunat
{
    public const CONEXION_SEGUNDOS = 10;

    public const RESPUESTA_SEGUNDOS = 25;

    public function endpoint(#[\SensitiveParameter] ConfiguracionFacturacion $config, bool $consultaCdr = false): string
    {
        if ($consultaCdr) {
            ReglasFiscales::exigir($config->entorno->value === 'produccion', 'No existe una consulta CDR de facturas Beta configurada. No se utilizará Producción como alternativa.');

            return SunatEndpoints::FE_CONSULTA_CDR;
        }

        return $config->entorno->value === 'beta' ? SunatEndpoints::FE_BETA : SunatEndpoints::FE_PRODUCCION;
    }

    public function cliente(#[\SensitiveParameter] ConfiguracionFacturacion $config, string $ruc, bool $consultaCdr = false): SoapClient
    {
        $endpoint = $this->endpoint($config, $consultaCdr);
        // Greenter's default client disables TLS verification. Always supply explicit verified options.
        $cliente = new SoapClient($consultaCdr ? WsdlProvider::getConsultPath() : WsdlProvider::getBillPath(), $this->opcionesCliente());
        $cliente->setService($endpoint);
        if (trim($config->usuario_sol ?? '') === '' || ($config->clave_sol ?? '') === '') {
            throw new FalloSunat('credenciales_incompletas');
        }
        if (str_starts_with($config->usuario_sol ?? '', $ruc)) {
            throw new FalloSunat('usuario_sol_formato');
        }
        $cliente->setCredentials($ruc.($config->usuario_sol ?? ''), $config->clave_sol ?? '');

        return $cliente;
    }

    /** @return array<string, mixed> */
    protected function opcionesCliente(): array
    {
        return [
            'trace' => false,
            'exceptions' => true,
            'soap_version' => SOAP_1_1,
            'connection_timeout' => self::CONEXION_SEGUNDOS,
            'keep_alive' => false,
            'cache_wsdl' => WSDL_CACHE_NONE,
            // Native SOAP generates Content-Type, SOAPAction, Host, Content-Length and Connection.
            // Do not add duplicate manual headers or HTTP Basic credentials for WS-Security.
            'stream_context' => stream_context_create([
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
                'http' => ['protocol_version' => 1.1],
            ]),
        ];
    }

    public function enviar(#[\SensitiveParameter] ConfiguracionFacturacion $config, string $ruc, string $tipo, string $nombre, #[\SensitiveParameter] string $xml): RespuestaSunat
    {
        $archivo = tempnam(sys_get_temp_dir(), 'sunat-');
        if ($archivo === false) {
            throw new FalloSunat('zip_preparacion');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($archivo, ZipArchive::OVERWRITE) !== true || ! $zip->addFromString($nombre.'.xml', $xml) || ! $zip->close()) {
                throw new FalloSunat('zip_preparacion');
            }
            $contenido = file_get_contents($archivo);
            if (! is_string($contenido)) {
                throw new FalloSunat('zip_preparacion');
            }
        } catch (Throwable) {
            throw new FalloSunat('zip_preparacion');
        } finally {
            @unlink($archivo);
        }
        $esResumen = $tipo === '03' && preg_match('/^'.preg_quote($ruc, '/').'-RC-\d{8}-[1-9]\d{0,4}$/D', $nombre) === 1;
        ReglasFiscales::exigir($esResumen || preg_match('/^'.preg_quote($ruc, '/').'-'.preg_quote($tipo, '/').'-[FB][A-Z0-9]{3}-[0-9]{1,8}$/D', $nombre) === 1, 'La identidad del archivo no corresponde al documento.');
        $respuesta = $this->llamar($config, $ruc, false, $esResumen ? 'sendSummary' : 'sendBill', ['parameters' => ['fileName' => $nombre.'.zip', 'contentFile' => $contenido]]);
        if ($esResumen && is_object($respuesta) && isset($respuesta->ticket) && is_string($respuesta->ticket) && $respuesta->ticket !== '') {
            return new RespuestaSunat(ticket: $respuesta->ticket);
        }
        if (! $esResumen && is_object($respuesta) && isset($respuesta->applicationResponse) && is_string($respuesta->applicationResponse) && $respuesta->applicationResponse !== '') {
            return new RespuestaSunat(cdr: $respuesta->applicationResponse);
        }
        throw new FalloSunat('respuesta_invalida');
    }

    public function consultar(#[\SensitiveParameter] ConfiguracionFacturacion $config, Comprobante $c, ?string $ticket): RespuestaSunat
    {
        if ($config->entorno->value !== XmlFiscal::dato($c, 'entorno') || $c->entorno !== XmlFiscal::dato($c, 'entorno')) {
            throw new FalloSunat('entorno_inconsistente');
        }
        $ruc = (string) $c->emisor['ruc'];
        $esIndividual = ! ModalidadBoletas::resumen($c);
        if (! $esIndividual) {
            ReglasFiscales::exigir($ticket !== null && $ticket !== '', 'El envío no devolvió ticket. Concilia su resultado antes de volver a informar la boleta.');

            return $this->consultarTicket($config, $ruc, $ticket, XmlFiscal::dato($c, 'entorno'));
        }
        ReglasFiscales::exigir($config->entorno->value === 'produccion', 'La consulta de CDR de comprobantes individuales está disponible en producción. En beta revisa el resultado con el servicio de pruebas.');
        $respuesta = $this->llamar($config, $ruc, true, 'getStatusCdr', ['parameters' => ['rucComprobante' => $ruc, 'tipoComprobante' => XmlFiscal::dato($c, 'tipo'), 'serieComprobante' => XmlFiscal::dato($c, 'serie'), 'numeroComprobante' => XmlFiscal::correlativoFiscal($c)]]);

        return $this->interpretarEstado(is_object($respuesta) ? ($respuesta->statusCdr ?? null) : null, true);
    }

    public function consultarTicket(#[\SensitiveParameter] ConfiguracionFacturacion $config, string $ruc, #[\SensitiveParameter] string $ticket, string $entorno): RespuestaSunat
    {
        if ($config->entorno->value !== $entorno) {
            throw new FalloSunat('entorno_inconsistente');
        }
        ReglasFiscales::exigir($ticket !== '', 'No hay un ticket para consultar.');
        $respuesta = $this->llamar($config, $ruc, false, 'getStatus', ['parameters' => ['ticket' => $ticket]]);

        return $this->interpretarEstado(is_object($respuesta) ? ($respuesta->status ?? null) : null, false);
    }

    private function interpretarEstado(mixed $estado, bool $esIndividual): RespuestaSunat
    {
        if (is_object($estado)) {
            $codigo = isset($estado->statusCode) && (is_string($estado->statusCode) || is_int($estado->statusCode)) ? (string) $estado->statusCode : '';
            if (! preg_match('/^\d{1,5}$/D', $codigo)) {
                throw new FalloSunat('respuesta_invalida');
            }
            if (isset($estado->content) && is_string($estado->content) && $estado->content !== '') {
                if (! $esIndividual && ! in_array($codigo, ['0', '99'], true)) {
                    throw new FalloSunat('respuesta_invalida');
                }

                return new RespuestaSunat(cdr: $estado->content, codigo: $codigo);
            }

            return new RespuestaSunat(pendiente: true, codigo: $codigo);
        }
        throw new FalloSunat('respuesta_invalida');
    }

    /** @param array<string, mixed> $argumentos */
    private function llamar(#[\SensitiveParameter] ConfiguracionFacturacion $config, string $ruc, bool $consultaCdr, string $metodo, #[\SensitiveParameter] array $argumentos): mixed
    {
        // http.timeout is ignored by native SOAP. Scope its documented response timeout to this call.
        $anterior = ini_get('default_socket_timeout');
        if (ini_set('default_socket_timeout', (string) self::RESPUESTA_SEGUNDOS) === false) {
            throw new FalloSunat('soap_configuracion_local');
        }
        try {
            $cliente = $consultaCdr ? $this->cliente($config, $ruc, true) : $this->cliente($config, $ruc);

            return $cliente->call($metodo, $argumentos);
        } catch (Throwable $error) {
            // Do not chain the exception: SOAP detail and traces can contain WS-Security credentials.
            throw FalloSunat::desde($error);
        } finally {
            ini_set('default_socket_timeout', $anterior);
        }
    }
}
