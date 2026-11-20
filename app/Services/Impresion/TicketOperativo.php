<?php

namespace App\Services\Impresion;

use Illuminate\Support\Str;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\MemoryPrintConnector;
use Mike42\Escpos\Printer;

/** @phpstan-import-type Contenido from FuenteImpresion */
class TicketOperativo
{
    /** @param Contenido $contenido */
    public function texto(array $contenido, int $ancho, bool $copia = false, string $folio = '', string $fecha = '', string $usuario = '', string $motivo = ''): string
    {
        $columnas = $ancho === 58 ? 32 : 48;
        $lineas = [];
        $agregar = function (string $texto) use (&$lineas, $columnas): void {
            $limpio = Str::ascii(preg_replace('/[\p{C}]/u', ' ', $texto) ?? '');
            foreach (explode("\n", wordwrap($limpio, $columnas, "\n", true)) as $linea) {
                $lineas[] = $linea;
            }
        };
        $importe = function (string $nombre, string $monto) use (&$lineas, $columnas, $agregar): void {
            $nombre = Str::ascii(preg_replace('/[\p{C}]/u', ' ', $nombre) ?? '');
            $monto = 'S/ '.$monto;
            if (strlen($nombre) + strlen($monto) + 1 > $columnas) {
                $agregar($nombre);
                $nombre = '';
            }
            $lineas[] = $nombre.str_repeat(' ', max(1, $columnas - strlen($nombre) - strlen($monto))).$monto;
        };
        $separador = str_repeat('-', $columnas);
        if (($contenido['formato'] ?? null) === 'comanda_simple') {
            if ($copia) {
                $agregar('*** COPIA / REIMPRESIÓN ***');
                $agregar('NO DUPLICAR PREPARACIÓN');
            }
            $agregar(str_pad('COMANDA', $columnas, ' ', STR_PAD_BOTH));
            $lineas[] = $separador;
            $agregar($contenido['referencia']);
            $agregar('FECHA: '.$contenido['fecha']);
            foreach ($contenido['datos'] as $texto) {
                $agregar($texto);
            }
            $lineas[] = $separador;
            $agregar(str_pad('PLATOS E INSUMOS', $columnas, ' ', STR_PAD_BOTH));
            $lineas[] = $separador;
            foreach ($contenido['lineas'] as $fila) {
                $agregar($fila['cantidad'].' x '.$fila['descripcion']);
                if ($fila['nota'] !== '') {
                    $agregar('  NOTA: '.$fila['nota']);
                }
                foreach ($fila['extra'] as $texto) {
                    $agregar('  '.$texto);
                }
                $agregar('');
            }

            return implode("\n", $lineas)."\n";
        }
        if (in_array($contenido['formato'] ?? null, ['precuenta_compacta', 'venta_compacta'], true)) {
            $centrar = function (string $texto) use ($agregar, $columnas): void {
                $limpio = Str::ascii(preg_replace('/[\p{C}]/u', ' ', $texto) ?? '');
                foreach (explode("\n", wordwrap($limpio, $columnas, "\n", true)) as $linea) {
                    $agregar(str_pad($linea, $columnas, ' ', STR_PAD_BOTH));
                }
            };
            if ($copia) {
                $centrar('*** COPIA / REIMPRESIÓN ***');
            }
            foreach ($contenido['empresa'] as $texto) {
                $centrar($texto);
            }
            $centrar($contenido['titulo']);
            $centrar($contenido['referencia']);
            $lineas[] = $separador;
            $agregar(($contenido['formato'] === 'precuenta_compacta' ? 'FECHA DEL PEDIDO: ' : 'FECHA DE EMISIÓN: ').$contenido['fecha']);
            foreach ($contenido['datos'] as $texto) {
                $agregar($texto);
            }
            $lineas[] = $separador;
            $agregar('DETALLE DE CONSUMO');
            $descripcionAncho = $columnas - 24;
            $lineas[] = str_pad('CANT', 4).' '.str_pad('PRODUCTO', $descripcionAncho).' '.str_pad('P.U.', 8, ' ', STR_PAD_LEFT).' '.str_pad('IMP.', 9, ' ', STR_PAD_LEFT);
            $lineas[] = $separador;
            foreach ($contenido['lineas'] as $fila) {
                $precio = $fila['precio'] ?? '0.00';
                $total = $fila['total'] ?? '0.00';
                if (strlen($fila['cantidad']) > 4 || strlen($precio) > 8 || strlen($total) > 9) {
                    $agregar($fila['cantidad'].' x '.$fila['descripcion']);
                    $importe('P.U.', $precio);
                    $importe('Importe', $total);
                } else {
                    $descripcion = Str::ascii(preg_replace('/[\p{C}]/u', ' ', $fila['descripcion']) ?? '');
                    $partes = explode("\n", wordwrap($descripcion, $descripcionAncho, "\n", true));
                    $lineas[] = str_pad($fila['cantidad'], 4).' '.str_pad(array_shift($partes), $descripcionAncho).' '.str_pad($precio, 8, ' ', STR_PAD_LEFT).' '.str_pad($total, 9, ' ', STR_PAD_LEFT);
                    foreach ($partes as $parte) {
                        $agregar('     '.$parte);
                    }
                }
                if (isset($fila['descuento']) && bccomp($fila['descuento'], '0', 2) > 0) {
                    $importe('  Descuento', '-'.$fila['descuento']);
                }
                foreach ($fila['extra'] as $texto) {
                    $agregar('  '.$texto);
                }
            }
            $lineas[] = $separador;
            foreach ($contenido['totales'] as $fila) {
                if (bccomp($fila['monto'], '0', 2) === 0 && in_array($fila['nombre'], ['Descuentos pendientes', 'Envío pendiente', 'Descuentos por producto', 'Descuento general', 'Envío', 'Op. gratuitas (referencial)'], true)) {
                    continue;
                }
                $importe(match ($fila['nombre']) {
                    'Productos pendientes', 'Productos' => 'SUBTOTAL CONSUMOS',
                    'Descuentos pendientes' => 'DESCUENTOS',
                    'Envío pendiente' => 'DELIVERY',
                    default => $fila['nombre'],
                }, $fila['monto']);
            }
            $agregar($contenido['letras'] ?? '');
            if ($contenido['pagos'] !== []) {
                $lineas[] = $separador;
                $agregar('FORMAS DE PAGO');
                foreach ($contenido['pagos'] as $pago) {
                    $importe($pago['nombre'], $pago['monto']);
                    foreach ($pago['extra'] as $texto) {
                        $agregar($texto);
                    }
                }
            }
            $lineas[] = $separador;
            foreach ($contenido['avisos'] as $texto) {
                $centrar($texto);
            }

            return implode("\n", $lineas)."\n";
        }
        if ($copia) {
            $agregar('*** COPIA / REIMPRESIÓN ***');
            $agregar('NO DUPLICAR PREPARACIÓN NI COBRO');
            $lineas[] = $separador;
        }
        foreach ($contenido['empresa'] as $texto) {
            $agregar($texto);
        }
        $agregar($contenido['titulo']);
        $lineas[] = $separador;
        $agregar($contenido['referencia']);
        $agregar('Origen: '.$contenido['fecha']);
        foreach ($contenido['datos'] as $texto) {
            $agregar($texto);
        }
        $lineas[] = $separador;
        foreach ($contenido['lineas'] as $fila) {
            $agregar($fila['cantidad'].' x '.$fila['descripcion']);
            if (isset($fila['precio'])) {
                $agregar('  Precio unitario: S/ '.$fila['precio']);
            }
            if (isset($fila['descuento']) && bccomp($fila['descuento'], '0', 2) > 0) {
                $agregar('  Descuento: -S/ '.$fila['descuento']);
            }
            if (isset($fila['total'])) {
                $importe('  Neto', $fila['total']);
            }
            if ($fila['nota'] !== '') {
                $agregar('  NOTA: '.$fila['nota']);
            }
            foreach ($fila['extra'] as $texto) {
                $agregar('  '.$texto);
            }
            $agregar('');
        }
        if ($contenido['totales'] !== []) {
            $lineas[] = $separador;
        }
        foreach ($contenido['totales'] as $fila) {
            $importe($fila['nombre'], $fila['monto']);
        }
        if ($contenido['pagos'] !== []) {
            $lineas[] = $separador;
            $agregar('PAGOS ORIGINALES');
        }
        foreach ($contenido['pagos'] as $pago) {
            $importe($pago['nombre'], $pago['monto']);
            foreach ($pago['extra'] as $texto) {
                $agregar($texto);
            }
        }
        $lineas[] = $separador;
        foreach ($contenido['avisos'] as $texto) {
            $agregar($texto);
        }
        if ($copia) {
            $agregar('Copia histórica. Revisar avance y anulaciones.');
            $agregar('Motivo: '.$motivo);
        }
        if ($folio !== '') {
            $agregar('Impresión: '.$folio);
        }
        if ($fecha !== '') {
            $agregar('Generado: '.$fecha);
        }
        if ($usuario !== '') {
            $agregar('Por: '.$usuario);
        }

        return implode("\n", $lineas)."\n";
    }

    /** @param array{fuente:string,tamano:int,grafica:bool}|null $tipografia */
    public function generar(string $texto, ?string $qr = null, ?string $logo = null, ?array $tipografia = null, int $ancho = 80): string
    {
        $conector = new MemoryPrintConnector;
        $impresora = new Printer($conector);
        try {
            $impresora->setFont(Printer::FONT_A);
            $raster = app(TicketRaster::class);
            if ($logo !== null && ($imagenLogo = $raster->logo($logo, $ancho)) !== null) {
                try {
                    $impresora->setJustification(Printer::JUSTIFY_LEFT);
                    $impresora->bitImage(EscposImage::load($imagenLogo, false, ['gd']));
                    $impresora->feed();
                } finally {
                    @unlink($imagenLogo);
                }
                $impresora->setJustification(Printer::JUSTIFY_LEFT);
            }
            if ($tipografia !== null && $tipografia['grafica']) {
                $imagenTexto = $raster->texto($texto, $tipografia, $ancho);
                try {
                    $impresora->bitImage(EscposImage::load($imagenTexto, false, ['gd']));
                } finally {
                    @unlink($imagenTexto);
                }
            } else {
                $impresora->text($texto);
            }
            if ($qr !== null) {
                $impresora->setJustification(Printer::JUSTIFY_CENTER);
                $impresora->qrCode($qr, Printer::QR_ECLEVEL_M, 4);
                $impresora->setJustification(Printer::JUSTIFY_LEFT);
            }
            $impresora->feed(4);
            $impresora->cut();

            return $conector->getData();
        } finally {
            $impresora->close();
        }
    }
}
