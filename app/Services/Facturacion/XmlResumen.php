<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Greenter\Model\Summary\Summary;
use Greenter\Xml\Builder\SummaryBuilder;

class XmlResumen
{
    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const SAC = 'urn:sunat:names:specification:ubl:peru:schema:xsd:SunatAggregateComponents-1';

    /** @param Comprobante|list<Comprobante> $c */
    public function generar(Summary $resumen, Comprobante|array $c): string
    {
        $xml = (new SummaryBuilder(['autoescape' => 'html']))->build($resumen);
        ReglasFiscales::exigir($xml !== null, 'No se pudo generar el resumen.');
        $dom = new DOMDocument;
        ReglasFiscales::exigir($dom->loadXML($xml, LIBXML_NONET), 'Resumen XML inválido.');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('sac', self::SAC);
        $xp->registerNamespace('cac', self::CAC);
        $xp->registerNamespace('cbc', self::CBC);
        $lineas = $xp->query('/*/sac:SummaryDocumentsLine');
        $boletas = $c instanceof Comprobante ? [$c] : $c;
        ReglasFiscales::exigir($lineas !== false && $lineas->length === count($boletas) && count($boletas) > 0, 'El resumen no contiene todas las boletas.');
        foreach ($lineas as $indice => $linea) {
            ReglasFiscales::exigir($linea instanceof DOMElement, 'Línea de resumen inválida.');
            $c = $boletas[$indice];
            $clientes = $xp->query('cac:AccountingCustomerParty', $linea);
            $cliente = $clientes !== false ? $clientes->item(0) : null;
            if ($cliente instanceof DOMElement) {
                $party = $cliente->appendChild($dom->createElementNS(self::CAC, 'cac:Party'));
                $legal = $party->appendChild($dom->createElementNS(self::CAC, 'cac:PartyLegalEntity'));
                $nombre = $dom->createElementNS(self::CBC, 'cbc:RegistrationName');
                $nombre->appendChild($dom->createTextNode((string) $c->receptor['nombre']));
                $legal->appendChild($nombre);
            }
            // Greenter Lite's bundled summary template predates the mandatory tax rate.
            foreach ($xp->query('cac:TaxTotal', $linea) ?: [] as $tax) {
                if ($tax instanceof \DOMNode) {
                    $linea->removeChild($tax);
                }
            }
            $this->impuesto($dom, $linea, '1000', 'IGV', $c->impuestos, XmlFiscal::dato($c, 'gravadas'), XmlFiscal::dato($c, 'tasa'));
            if (bccomp($c->operaciones_gratuitas, '0', 2) > 0) {
                $baseGratuitaGravada = '0.00';
                foreach ($c->detalles as $detalle) {
                    if (in_array($detalle->tipo_afectacion_igv, ['11', '12', '13', '14', '15', '16'], true)) {
                        $baseGratuitaGravada = bcadd($baseGratuitaGravada, $detalle->base_imponible, 2);
                    }
                }
                // Free-transfer tax is reported separately and never increases the amount payable.
                $this->impuesto($dom, $linea, '9996', 'GRA', XmlFiscal::dato($c, 'igv_gratuitas'), $baseGratuitaGravada, XmlFiscal::dato($c, 'tasa'));
            }
        }
        $salida = $dom->saveXML();
        ReglasFiscales::exigir(is_string($salida), 'No se pudo serializar el resumen.');

        return $salida;
    }

    private function impuesto(DOMDocument $dom, DOMElement $linea, string $codigo, string $nombre, string $monto, string $base, string $tasa): void
    {
        $total = $linea->appendChild($dom->createElementNS(self::CAC, 'cac:TaxTotal'));
        $this->importe($dom, $total, 'TaxAmount', $monto);
        $subtotal = $total->appendChild($dom->createElementNS(self::CAC, 'cac:TaxSubtotal'));
        $this->importe($dom, $subtotal, 'TaxableAmount', $base);
        $this->importe($dom, $subtotal, 'TaxAmount', $monto);
        $categoria = $subtotal->appendChild($dom->createElementNS(self::CAC, 'cac:TaxCategory'));
        $categoria->appendChild($dom->createElementNS(self::CBC, 'cbc:Percent', $tasa));
        $esquema = $categoria->appendChild($dom->createElementNS(self::CAC, 'cac:TaxScheme'));
        $esquema->appendChild($dom->createElementNS(self::CBC, 'cbc:ID', $codigo));
        $esquema->appendChild($dom->createElementNS(self::CBC, 'cbc:Name', $nombre));
        $esquema->appendChild($dom->createElementNS(self::CBC, 'cbc:TaxTypeCode', $codigo === '1000' ? 'VAT' : 'FRE'));
    }

    private function importe(DOMDocument $dom, \DOMNode $padre, string $nombre, string $valor): void
    {
        $elemento = $dom->createElementNS(self::CBC, 'cbc:'.$nombre, $valor);
        $elemento->setAttribute('currencyID', 'PEN');
        $padre->appendChild($elemento);
    }
}
