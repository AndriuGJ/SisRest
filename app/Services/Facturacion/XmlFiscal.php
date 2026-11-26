<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\Charge;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;
use Greenter\See;
use Greenter\XMLSecLibs\Sunat\SignedXml;

class XmlFiscal
{
    public static function dato(Comprobante $c, string $campo): string
    {
        $valor = $c->configuracion_tributaria[$campo] ?? '';

        return is_string($valor) ? $valor : '';
    }

    public static function nombre(Comprobante $c): string
    {
        return (string) $c->emisor['ruc'].'-'.self::dato($c, 'tipo').'-'.self::dato($c, 'serie').'-'.self::correlativoFiscal($c);
    }

    public static function numero(Comprobante $c): string
    {
        return self::dato($c, 'serie').'-'.str_pad((string) $c->correlativo, 8, '0', STR_PAD_LEFT);
    }

    public static function correlativoFiscal(Comprobante $c): string
    {
        // Missing snapshot means a legacy identity. Never change its signed number or filename.
        return self::dato($c, 'correlativo_xml') ?: (string) $c->correlativo;
    }

    public static function numeroFiscal(Comprobante $c): string
    {
        return self::dato($c, 'serie').'-'.self::correlativoFiscal($c);
    }

    public function factura(Comprobante $c): Invoice
    {
        $cliente = (new Client)->setTipoDoc((string) $c->receptor['tipo'])->setNumDoc((string) $c->receptor['numero'])
            ->setRznSocial(ReglasFiscales::texto((string) $c->receptor['nombre']));
        if (trim((string) $c->receptor['direccion']) !== '') {
            $cliente->setAddress((new Address)->setDireccion(ReglasFiscales::texto((string) $c->receptor['direccion']))->setCodigoPais('PE'));
        }
        $invoice = (new Invoice)->setUblVersion('2.1')->setTipoOperacion('0101')
            ->setTipoDoc(self::dato($c, 'tipo'))->setSerie(self::dato($c, 'serie'))->setCorrelativo(self::correlativoFiscal($c))
            ->setFechaEmision(CarbonImmutable::parse(self::dato($c, 'fecha_hora')))->setTipoMoneda($c->moneda)
            ->setFormaPago(new FormaPagoContado)->setCompany($this->empresa($c))
            ->setClient($cliente)
            ->setValorVenta((float) $c->valor_venta)->setSubTotal((float) $c->total)->setMtoImpVenta((float) $c->total)
            ->setMtoOperGravadas((float) self::dato($c, 'gravadas'))->setMtoOperExoneradas((float) self::dato($c, 'exoneradas'))
            ->setMtoOperInafectas((float) self::dato($c, 'inafectas'))->setMtoOperGratuitas((float) $c->operaciones_gratuitas)
            ->setMtoIGVGratuitas((float) self::dato($c, 'igv_gratuitas'))->setMtoIGV((float) $c->impuestos)->setTotalImpuestos((float) $c->impuestos);
        $lineas = [];
        foreach ($c->detalles()->orderBy('id')->get() as $d) {
            $linea = (new SaleDetail)->setCodProducto($d->codigo_producto)->setDescripcion($d->descripcion)
                ->setUnidad($d->unidad_sunat)->setCantidad((float) $d->cantidad)->setMtoValorUnitario((float) $d->valor_unitario)
                ->setMtoPrecioUnitario((float) $d->precio_unitario)->setMtoValorVenta((float) $d->base_imponible)
                ->setMtoBaseIgv((float) $d->base_imponible)->setTipAfeIgv($d->tipo_afectacion_igv)
                ->setPorcentajeIgv((float) $d->tasa_impuesto)->setIgv((float) $d->impuestos)->setTotalImpuestos((float) $d->impuestos);
            if (bccomp($d->valor_referencial, '0', 6) > 0) {
                $linea->setMtoValorGratuito((float) $d->valor_referencial);
            }
            if (bccomp($d->descuento, '0', 2) > 0) {
                $base = bcadd($d->base_imponible, $d->descuento, 2);
                $factor = ReglasFiscales::redondear(bcdiv($d->descuento, $base, 12), 5);
                ReglasFiscales::exigir(bccomp($factor, '0', 5) > 0 && bccomp(bcsub(ReglasFiscales::redondear(bcmul($base, $factor, 12)), $d->descuento, 2), '-0.01', 2) >= 0 && bccomp(bcsub(ReglasFiscales::redondear(bcmul($base, $factor, 12)), $d->descuento, 2), '0.01', 2) <= 0, 'El descuento no puede representarse con la precisión tributaria permitida.');
                $linea->setDescuentos([(new Charge)->setCodTipo('00')->setMontoBase((float) $base)->setMonto((float) $d->descuento)->setFactor((float) $factor)]);
            }
            $lineas[] = $linea;
        }
        $leyendas = [(new Legend)->setCode('1000')->setValue(self::dato($c, 'leyenda'))];
        if (bccomp($c->operaciones_gratuitas, '0', 2) > 0) {
            $leyendas[] = (new Legend)->setCode('1002')->setValue('TRANSFERENCIA GRATUITA DE UN BIEN Y/O SERVICIO PRESTADO GRATUITAMENTE');
        }

        return $invoice->setDetails($lineas)->setLegends($leyendas);
    }

    public function resumen(Comprobante $c, int $numero, CarbonImmutable $fecha): Summary
    {
        $detalle = (new SummaryDetail)->setTipoDoc('03')->setSerieNro(self::numeroFiscal($c))->setEstado('1')
            ->setClienteTipo((string) $c->receptor['tipo'])->setClienteNro((string) $c->receptor['numero'])
            ->setTotal((float) $c->total)->setMtoOperGravadas((float) self::dato($c, 'gravadas'))
            ->setMtoOperExoneradas((float) self::dato($c, 'exoneradas'))->setMtoOperInafectas((float) self::dato($c, 'inafectas'))
            ->setMtoOperGratuitas((float) $c->operaciones_gratuitas)->setMtoIGV((float) $c->impuestos);

        return (new Summary)->setFecGeneracion($c->fecha_emision)->setFecResumen($fecha)->setCorrelativo((string) $numero)
            ->setCompany($this->empresa($c))->setMoneda($c->moneda)->setDetails([$detalle]);
    }

    /** @param list<Comprobante> $boletas */
    public function resumenAgrupado(array $boletas, int $numero, CarbonImmutable $fecha): Summary
    {
        ReglasFiscales::exigir(count($boletas) > 0, 'Selecciona boletas para el resumen.');
        $base = $this->resumen($boletas[0], $numero, $fecha);
        $lineas = [];
        foreach ($boletas as $boleta) {
            $lineas[] = $this->resumen($boleta, $numero, $fecha)->getDetails()[0];
        }

        return $base->setDetails($lineas);
    }

    /** @param Comprobante|list<Comprobante>|null $comprobante */
    public function firmar(DocumentInterface $documento, #[\SensitiveParameter] string $pem, Comprobante|array|null $comprobante = null): string
    {
        if ($documento instanceof Summary) {
            ReglasFiscales::exigir($comprobante !== null, 'El resumen requiere el comprobante de origen.');
            $signer = new SignedXml;
            $signer->setCertificate($pem);
            $xml = $signer->signXml(app(XmlResumen::class)->generar($documento, $comprobante));
            $this->hash($xml);

            return $xml;
        }
        $see = new See;
        $see->setBuilderOptions(['autoescape' => 'html']);
        $see->setCertificate($pem);
        $xml = $see->getXmlSigned($documento);
        ReglasFiscales::exigir(is_string($xml) && $xml !== '', 'No se pudo generar el XML firmado.');
        $this->hash($xml);

        return $xml;
    }

    public function hash(string $xml): string
    {
        $dom = new DOMDocument;
        ReglasFiscales::exigir(@$dom->loadXML($xml, LIBXML_NONET) && $dom->doctype === null, 'El XML firmado no es válido.');
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $hash = $xpath->evaluate('string(//ds:SignedInfo/ds:Reference/ds:DigestValue)');
        ReglasFiscales::exigir(is_string($hash) && $hash !== '', 'El XML no contiene la firma digital.');

        return $hash;
    }

    private function empresa(Comprobante $c): Company
    {
        $e = $c->emisor;
        $dir = (new Address)->setUbigueo((string) $e['ubigeo'])->setDepartamento(ReglasFiscales::texto((string) $e['departamento']))
            ->setProvincia(ReglasFiscales::texto((string) $e['provincia']))->setDistrito(ReglasFiscales::texto((string) $e['distrito']))
            ->setDireccion(ReglasFiscales::texto((string) $e['direccion']))->setCodigoPais('PE')->setCodLocal('0000');

        return (new Company)->setRuc((string) $e['ruc'])->setRazonSocial(ReglasFiscales::texto((string) $e['razon_social']))
            ->setNombreComercial(ReglasFiscales::texto((string) ($e['nombre_comercial'] ?? '')))->setAddress($dir);
    }
}
