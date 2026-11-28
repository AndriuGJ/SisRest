<?php

namespace App\Services\Facturacion;

use App\Models\ConfiguracionFacturacion;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class CertificadoFiscal
{
    public function pem(#[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $clave): string
    {
        if (str_contains($pfx, '-----BEGIN CERTIFICATE-----')) {
            $cert = @openssl_x509_read($pfx);
            $key = @openssl_pkey_get_private($pfx, $clave);
        } else {
            $certificados = [];
            ReglasFiscales::exigir(@openssl_pkcs12_read($pfx, $certificados, $clave), 'No se pudo abrir el certificado PFX/P12. Revisa la contraseña y los algoritmos del archivo. Si usa RC2 antiguo, conviértelo a PEM de forma controlada, sin cambiar la seguridad global de OpenSSL.', 'certificado');
            $cert = @openssl_x509_read($certificados['cert'] ?? '');
            $key = @openssl_pkey_get_private($certificados['pkey'] ?? '');
        }
        ReglasFiscales::exigir($cert !== false && $key !== false && @openssl_x509_check_private_key($cert, $key), 'El certificado no contiene una clave privada válida o no corresponde a ella.', 'certificado');
        $datos = openssl_x509_parse($cert);
        ReglasFiscales::exigir($datos !== false && ($datos['validFrom_time_t'] ?? PHP_INT_MAX) <= time() && ($datos['validTo_time_t'] ?? 0) > time(), 'El certificado aún no es válido o ha vencido.', 'certificado');
        $certPem = $keyPem = '';
        ReglasFiscales::exigir(openssl_x509_export($cert, $certPem) && openssl_pkey_export($key, $keyPem), 'No se pudo preparar el certificado para firmar.', 'certificado');

        return $keyPem."\n".$certPem;
    }

    public function cargar(ConfiguracionFacturacion $config): string
    {
        ReglasFiscales::exigir($config->certificado_ruta !== null && Storage::disk('local')->exists($config->certificado_ruta), 'Configura el certificado digital antes de emitir.');

        return $this->pem(Crypt::decryptString(Storage::disk('local')->get($config->certificado_ruta)), $config->clave_certificado ?? '');
    }
}
