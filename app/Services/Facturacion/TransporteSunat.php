<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;
use App\Models\ConfiguracionFacturacion;
use Greenter\Ws\Services\SoapClient;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\Ws\Services\WsdlProvider;
use RuntimeException;
use ZipArchive;

class TransporteSunat
{
    public function cliente(ConfiguracionFacturacion $config, string $ruc, bool $consultaCdr = false): SoapClient
    {
        // Greenter's default client disables TLS verification. Always supply explicit verified options.
        $cliente = new SoapClient($consultaCdr ? WsdlProvider::getConsultPath() : WsdlProvider::getBillPath(), [
            'trace' => false, 'exceptions' => true, 'connection_timeout' => 10,
            'stream_context' => stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false], 'http' => ['timeout' => 25]]),
        ]);
        $cliente->setService($consultaCdr ? SunatEndpoints::FE_CONSULTA_CDR : ($config->entorno->value === 'beta' ? SunatEndpoints::FE_BETA : SunatEndpoints::FE_PRODUCCION));
        $cliente->setCredentials($ruc.($config->usuario_sol ?? ''), $config->clave_sol ?? '');

        return $cliente;
    }

    public function enviar(ConfiguracionFacturacion $config, string $ruc, string $tipo, string $nombre, string $xml): RespuestaSunat
    {
        $archivo = tempnam(sys_get_temp_dir(), 'sunat-');
        if ($archivo === false) {
            throw new RuntimeException('No se pudo preparar el ZIP.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($archivo, ZipArchive::OVERWRITE) !== true || ! $zip->addFromString($nombre.'.xml', $xml) || ! $zip->close()) {
                throw new RuntimeException('No se pudo preparar el ZIP.');
            }
            $contenido = file_get_contents($archivo);
            if (! is_string($contenido)) {
                throw new RuntimeException('No se pudo leer el ZIP.');
            }
        } finally {
            @unlink($archivo);
        }
        $respuesta = $this->cliente($config, $ruc)->call($tipo === '03' ? 'sendSummary' : 'sendBill', ['parameters' => ['fileName' => $nombre.'.zip', 'contentFile' => $contenido]]);
        if ($tipo === '03' && is_object($respuesta) && isset($respuesta->ticket) && is_string($respuesta->ticket) && $respuesta->ticket !== '') {
            return new RespuestaSunat(ticket: $respuesta->ticket);
        }
        if ($tipo === '01' && is_object($respuesta) && isset($respuesta->applicationResponse) && is_string($respuesta->applicationResponse) && $respuesta->applicationResponse !== '') {
            return new RespuestaSunat(cdr: $respuesta->applicationResponse);
        }
        throw new RuntimeException('SUNAT no devolvió una constancia ni un ticket.');
    }

    public function consultar(ConfiguracionFacturacion $config, Comprobante $c, ?string $ticket): RespuestaSunat
    {
        $ruc = (string) $c->emisor['ruc'];
        $esFactura = XmlFiscal::dato($c, 'tipo') === '01';
        if ($esFactura) {
            ReglasFiscales::exigir($config->entorno->value === 'produccion', 'La consulta de CDR de facturas está disponible en producción. En beta revisa el resultado con el servicio de pruebas.');
            $respuesta = $this->cliente($config, $ruc, true)->call('getStatusCdr', ['parameters' => ['rucComprobante' => $ruc, 'tipoComprobante' => '01', 'serieComprobante' => XmlFiscal::dato($c, 'serie'), 'numeroComprobante' => $c->correlativo]]);
            $estado = is_object($respuesta) ? ($respuesta->statusCdr ?? null) : null;
        } else {
            ReglasFiscales::exigir($ticket !== null && $ticket !== '', 'El envío no devolvió ticket. Concilia su resultado antes de volver a informar la boleta.');
            $respuesta = $this->cliente($config, $ruc)->call('getStatus', ['parameters' => ['ticket' => $ticket]]);
            $estado = is_object($respuesta) ? ($respuesta->status ?? null) : null;
        }
        if (is_object($estado)) {
            $codigo = isset($estado->statusCode) && (is_string($estado->statusCode) || is_int($estado->statusCode)) ? (string) $estado->statusCode : '';
            if (isset($estado->content) && is_string($estado->content) && $estado->content !== '') {
                return new RespuestaSunat(cdr: $estado->content, codigo: $codigo);
            }

            return new RespuestaSunat(pendiente: true, codigo: $codigo);
        }
        throw new RuntimeException('Respuesta de consulta inválida.');
    }
}
