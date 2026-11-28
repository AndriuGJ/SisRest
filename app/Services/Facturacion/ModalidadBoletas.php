<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;
use App\Models\ConfiguracionFacturacion;

final class ModalidadBoletas
{
    public static function configurada(ConfiguracionFacturacion $config): string
    {
        $modo = $config->entorno->value === 'beta' ? $config->modalidad_boletas_beta : $config->modalidad_boletas_produccion;
        ReglasFiscales::exigir(in_array($modo, ['individual', 'resumen_diario'], true), 'Configura una modalidad válida para las boletas.');
        ReglasFiscales::exigir($config->entorno->value !== 'produccion' || $modo !== 'individual' || $config->individual_produccion_confirmada, 'Confirma que el sistema de emisión autorizado del negocio permite boletas individuales en producción.');

        return $modo;
    }

    public static function de(Comprobante $c): string
    {
        // Never redirect an older pending or signed boleta to another fiscal operation.
        $modo = XmlFiscal::dato($c, 'tipo') === '03' ? (XmlFiscal::dato($c, 'modalidad_envio') ?: 'resumen_diario') : 'individual';
        ReglasFiscales::exigir(in_array($modo, ['individual', 'resumen_diario'], true), 'La modalidad guardada del comprobante requiere revisión.');

        return $modo;
    }

    public static function resumen(Comprobante $c): bool
    {
        return self::de($c) === 'resumen_diario';
    }
}
