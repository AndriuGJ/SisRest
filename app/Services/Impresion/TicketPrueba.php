<?php

namespace App\Services\Impresion;

use App\Models\PruebaImpresion;
use Illuminate\Support\Str;
use Mike42\Escpos\PrintConnectors\MemoryPrintConnector;
use Mike42\Escpos\Printer;

class TicketPrueba
{
    public function generar(PruebaImpresion $prueba): string
    {
        $conector = new MemoryPrintConnector;
        $impresora = new Printer($conector);
        try {
            $columnas = $prueba->configuracion['ancho_mm'] === 58 ? 32 : 48;
            $impresora->setJustification(Printer::JUSTIFY_CENTER);
            $impresora->setEmphasis(true);
            $impresora->text("SISREST - PRUEBA DE IMPRESION\n");
            $impresora->setEmphasis(false);
            $impresora->text("SIN VALOR COMERCIAL\n");
            $impresora->setJustification(Printer::JUSTIFY_LEFT);
            $impresora->text(str_repeat('-', $columnas)."\n");
            $nombre = Str::ascii(preg_replace('/[\x00-\x1F\x7F]/u', '', $prueba->configuracion['nombre']) ?? 'Impresora');
            $impresora->text(wordwrap($nombre, $columnas, "\n", true)."\n");
            $impresora->text($prueba->configuracion['host'].':'.$prueba->configuracion['puerto']."\n");
            $impresora->text('Papel: '.$prueba->configuracion['ancho_mm']." mm\n");
            $impresora->text($prueba->created_at->timezone('America/Lima')->format('d/m/Y H:i:s')."\n");
            $impresora->text('Prueba: '.substr($prueba->id, 0, 8)."\n");
            $impresora->text(str_repeat('-', $columnas)."\n");
            $impresora->text("Verifica texto, avance y corte.\n");
            $impresora->feed(4);
            $impresora->cut();

            return $conector->getData();
        } finally {
            $impresora->close();
        }
    }
}
