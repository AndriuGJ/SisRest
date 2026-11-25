<?php

namespace App\Services\Facturacion;

use DOMDocument;
use DOMXPath;
use ZipArchive;

class LeerCdr
{
    /** @return array{codigo:string,mensaje:string,observaciones:list<string>} */
    public function leer(#[\SensitiveParameter] string $contenido, string $nombre, string $ruc): array
    {
        if (strlen($contenido) > 2 * 1024 * 1024) {
            throw new FalloSunat('cdr_tamano');
        }
        $archivo = tempnam(sys_get_temp_dir(), 'cdr-');
        if ($archivo === false) {
            throw new FalloSunat('cdr_archivo');
        }
        try {
            if (file_put_contents($archivo, $contenido) !== strlen($contenido)) {
                throw new FalloSunat('cdr_archivo');
            }
            $zip = new ZipArchive;
            if ($zip->open($archivo, ZipArchive::RDONLY) !== true) {
                throw new FalloSunat('cdr_zip');
            }
            try {
                $stat = $zip->statName('R-'.$nombre.'.xml');
                if ($stat === false || $stat['size'] > 4 * 1024 * 1024 || $zip->numFiles !== 1) {
                    throw new FalloSunat('cdr_nombre');
                }
                $xml = $zip->getFromName('R-'.$nombre.'.xml');
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($archivo);
        }
        if (! is_string($xml) || str_contains($xml, '<!DOCTYPE') || str_contains($xml, '<!ENTITY')) {
            throw new FalloSunat('cdr_xml');
        }
        $dom = new DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET) || $dom->doctype !== null) {
            throw new FalloSunat('cdr_xml');
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $referencia = (string) $xp->evaluate('string(/*/cac:DocumentResponse/cac:Response/cbc:ReferenceID)');
        $receptor = (string) $xp->evaluate('string(/*/cac:ReceiverParty/cac:PartyIdentification/cbc:ID)');
        $sinRuc = substr($nombre, strlen($ruc) + 1);
        $numero = str_starts_with($sinRuc, '01-') ? substr($sinRuc, 3) : $sinRuc;
        if ($receptor !== $ruc || ! in_array($referencia, [$nombre, $sinRuc, $numero], true)) {
            throw new FalloSunat('cdr_referencia');
        }
        $codigo = (string) $xp->evaluate('string(/*/cac:DocumentResponse/cac:Response/cbc:ResponseCode)');
        if (! preg_match('/^\d{1,5}$/', $codigo)) {
            throw new FalloSunat('cdr_codigo');
        }
        $observaciones = [];
        foreach ($xp->query('/*/cbc:Note') ?: [] as $nota) {
            if ($nota instanceof \DOMNode) {
                $observaciones[] = mb_substr($nota->textContent, 0, 1000);
            }
        }

        return ['codigo' => $codigo, 'mensaje' => mb_substr((string) $xp->evaluate('string(/*/cac:DocumentResponse/cac:Response/cbc:Description)'), 0, 2000), 'observaciones' => array_slice($observaciones, 0, 30)];
    }
}
