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
        $cliente = new SoapClient($consultaCdr ? WsdlProvider::getConsultPath() : WsdlProvider::getBillPath(), [
            'trace' => false, 'exceptions' => true, 'connection_timeout' => 10,
            'stream_context' => stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false], 'http' => ['timeout' => 25]]),
        ]);
        $cliente->setService($endpoint);
        $cliente->setCredentials($ruc.($config->usuario_sol ?? ''), $config->clave_sol ?? '');

        return $cliente;
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
        $respuesta = $this->llamar($config, $ruc, false, $tipo === '03' ? 'sendSummary' : 'sendBill', ['parameters' => ['fileName' => $nombre.'.zip', 'contentFile' => $contenido]]);
        if ($tipo === '03' && is_object($respuesta) && isset($respuesta->ticket) && is_string($respuesta->ticket) && $respuesta->ticket !== '') {
            return new RespuestaSunat(ticket: $respuesta->ticket);
        }
        if ($tipo === '01' && is_object($respuesta) && isset($respuesta->applicationResponse) && is_string($respuesta->applicationResponse) && $respuesta->applicationResponse !== '') {
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
        $esFactura = XmlFiscal::dato($c, 'tipo') === '01';
        if ($esFactura) {
            ReglasFiscales::exigir($config->entorno->value === 'produccion', 'La consulta de CDR de facturas está disponible en producción. En beta revisa el resultado con el servicio de pruebas.');
            $respuesta = $this->llamar($config, $ruc, true, 'getStatusCdr', ['parameters' => ['rucComprobante' => $ruc, 'tipoComprobante' => '01', 'serieComprobante' => XmlFiscal::dato($c, 'serie'), 'numeroComprobante' => $c->correlativo]]);
            $estado = is_object($respuesta) ? ($respuesta->statusCdr ?? null) : null;
        } else {
            ReglasFiscales::exigir($ticket !== null && $ticket !== '', 'El envío no devolvió ticket. Concilia su resultado antes de volver a informar la boleta.');
            $respuesta = $this->llamar($config, $ruc, false, 'getStatus', ['parameters' => ['ticket' => $ticket]]);
            $estado = is_object($respuesta) ? ($respuesta->status ?? null) : null;
        }
        if (is_object($estado)) {
            $codigo = isset($estado->statusCode) && (is_string($estado->statusCode) || is_int($estado->statusCode)) ? (string) $estado->statusCode : '';
            if (! preg_match('/^\d{1,5}$/D', $codigo)) {
                throw new FalloSunat('respuesta_invalida');
            }
            if (isset($estado->content) && is_string($estado->content) && $estado->content !== '') {
                if (! $esFactura && ! in_array($codigo, ['0', '99'], true)) {
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
        try {
            $cliente = $consultaCdr ? $this->cliente($config, $ruc, true) : $this->cliente($config, $ruc);

            return $cliente->call($metodo, $argumentos);
        } catch (Throwable $error) {
            // Do not chain the exception: SOAP detail and traces can contain WS-Security credentials.
            throw FalloSunat::desde($error);
        }
    }
}
