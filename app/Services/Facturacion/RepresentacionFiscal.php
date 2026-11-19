<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** @phpstan-import-type Contenido from \App\Services\Impresion\FuenteImpresion */
class RepresentacionFiscal
{
    public function qr(Comprobante $c): string
    {
        return implode('|', [(string) $c->emisor['ruc'], XmlFiscal::dato($c, 'tipo'), XmlFiscal::dato($c, 'serie'), (string) $c->correlativo, $c->impuestos, $c->total, $c->fecha_emision->toDateString(), (string) $c->receptor['tipo'], (string) $c->receptor['numero']]);
    }

    public function qrImagen(Comprobante $c): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode((new Writer(new ImageRenderer(new RendererStyle(180), new SvgImageBackEnd)))->writeString($this->qr($c)));
    }

    /** @return Contenido */
    public function contenido(Comprobante $c): array
    {
        $empresa = [(string) ($c->emisor['nombre_comercial'] ?: $c->emisor['razon_social']), (string) $c->emisor['razon_social'], 'RUC: '.(string) $c->emisor['ruc'], (string) $c->emisor['direccion']];
        $lineas = [];
        foreach ($c->detalles()->orderBy('id')->get() as $d) {
            $lineas[] = ['descripcion' => $d->descripcion, 'cantidad' => $d->cantidad, 'nota' => '', 'extra' => ['Afectacion IGV: '.$d->tipo_afectacion_igv], 'precio' => $d->precio_unitario, 'total' => $d->total];
        }
        $avisos = [XmlFiscal::dato($c, 'leyenda'), 'Representacion impresa del comprobante electronico.', 'SUNAT: '.$c->estado_sunat->value, 'Hash: '.($c->hash_documento ?? '')];
        if (bccomp($c->operaciones_gratuitas, '0', 2) > 0) {
            $avisos[] = 'TRANSFERENCIA GRATUITA / SERVICIO PRESTADO GRATUITAMENTE';
        }
        if (XmlFiscal::dato($c, 'entorno') === 'beta') {
            array_unshift($avisos, 'BETA - PRUEBA SIN VALIDEZ TRIBUTARIA');
        }

        $tipo = $c->configuracion_tributaria['tipografia'] ?? null;
        $marca = ['logo' => is_string($c->emisor['logo_ruta'] ?? null) ? $c->emisor['logo_ruta'] : null];
        if (is_array($tipo) && is_string($tipo['fuente'] ?? null) && is_int($tipo['tickets'] ?? null) && is_bool($tipo['grafica'] ?? null)) {
            $marca['tipografia'] = ['fuente' => $tipo['fuente'], 'tamano' => $tipo['tickets'], 'grafica' => $tipo['grafica']];
        }

        return [...$marca, 'version' => 1, 'titulo' => XmlFiscal::dato($c, 'tipo') === '01' ? 'FACTURA ELECTRONICA' : 'BOLETA DE VENTA ELECTRONICA', 'empresa' => $empresa, 'referencia' => XmlFiscal::numero($c), 'fecha' => $c->fecha_emision->format('d/m/Y'),
            'datos' => [(string) $c->receptor['nombre'], 'Documento: '.(string) $c->receptor['numero'], (string) $c->receptor['direccion']], 'lineas' => $lineas,
            'totales' => [['nombre' => 'Valor venta', 'monto' => $c->valor_venta], ['nombre' => 'IGV / IPM', 'monto' => $c->impuestos], ['nombre' => 'Op. gratuitas (referencial)', 'monto' => $c->operaciones_gratuitas], ['nombre' => 'TOTAL', 'monto' => $c->total]], 'pagos' => [], 'avisos' => $avisos, 'qr' => $this->qr($c)];
    }
}
