<?php

namespace App\Services\Facturacion;

use App\Models\ConfiguracionFacturacion;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class CertificadoFiscal
{
    public function pem(string $pfx, string $clave): string
    {
        $certificados = [];
        ReglasFiscales::exigir(@openssl_pkcs12_read($pfx, $certificados, $clave), 'No se pudo abrir el certificado PFX/P12. Revisa el archivo y su contraseña.', 'certificado');
        $cert = $certificados['cert'] ?? '';
        $key = $certificados['pkey'] ?? '';
        ReglasFiscales::exigir($cert !== '' && $key !== '' && openssl_x509_check_private_key($cert, $key), 'El certificado no contiene una clave privada válida.', 'certificado');
        $datos = openssl_x509_parse($cert);
        ReglasFiscales::exigir($datos !== false && ($datos['validFrom_time_t'] ?? PHP_INT_MAX) <= time() && ($datos['validTo_time_t'] ?? 0) > time(), 'El certificado aún no es válido o ha vencido.', 'certificado');

        return $key."\n".$cert;
    }

    public function cargar(ConfiguracionFacturacion $config): string
    {
        ReglasFiscales::exigir($config->certificado_ruta !== null && Storage::disk('local')->exists($config->certificado_ruta), 'Configura el certificado digital antes de emitir.');

        return $this->pem(Crypt::decryptString(Storage::disk('local')->get($config->certificado_ruta)), $config->clave_certificado ?? '');
    }
}
