<?php

namespace App\Services\Impresion;

use App\Services\Configuracion\IdentidadVisual;
use GdImage;
use RuntimeException;
use Throwable;

class TicketRaster
{
    /** @param array{fuente:string,tamano:int,grafica:bool} $tipografia */
    public function texto(string $texto, array $tipografia, int $ancho): string
    {
        $fuente = app(IdentidadVisual::class)->fuenteArchivo($tipografia['fuente']);
        $tamano = max(9, min(16, $tipografia['tamano']));
        $pixeles = $ancho === 58 ? 384 : 576;
        $margen = 8;
        $anchoTexto = $pixeles - 2 * $margen;
        $lineas = [];
        foreach (explode("\n", rtrim($texto, "\r\n")) as $linea) {
            if (preg_match('/^-{8,}$/', $linea)) {
                $lineas[] = ['regla' => true, 'texto' => ''];

                continue;
            }
            if (preg_match('/^ {2,}(.+?) {2,}$/', $linea, $centrada)) {
                $lineas[] = ['texto' => $centrada[1], 'centrado' => true];

                continue;
            }
            $columnas = preg_split('/ {3,}/', trim($linea));
            if (is_array($columnas) && count($columnas) === 2 && $this->ancho($columnas[0].'  '.$columnas[1], $fuente, $tamano) <= $anchoTexto) {
                $lineas[] = ['texto' => $columnas[0], 'derecha' => $columnas[1]];

                continue;
            }
            $actual = '';
            foreach (str_split($linea) as $letra) {
                if ($actual !== '' && $this->ancho($actual.$letra, $fuente, $tamano) > $anchoTexto) {
                    $lineas[] = ['texto' => $actual];
                    $actual = '';
                }
                $actual .= $letra;
            }
            $lineas[] = ['texto' => $actual];
        }
        $altoLinea = (int) ceil($tamano * 2.1);
        $imagen = $this->lienzo($pixeles, max($altoLinea, count($lineas) * $altoLinea + 12));
        $negro = 0x000000;
        $y = $altoLinea;
        foreach ($lineas as $linea) {
            if ($linea['regla'] ?? false) {
                imageline($imagen, $margen, $y - 7, $pixeles - $margen, $y - 7, $negro);
            } else {
                $x = ($linea['centrado'] ?? false) ? max($margen, (int) (($pixeles - $this->ancho($linea['texto'], $fuente, $tamano)) / 2)) : $margen;
                imagettftext($imagen, $tamano, 0, $x, $y, $negro, $fuente, $linea['texto']);
                if (isset($linea['derecha'])) {
                    imagettftext($imagen, $tamano, 0, $pixeles - $margen - $this->ancho($linea['derecha'], $fuente, $tamano), $y, $negro, $fuente, $linea['derecha']);
                }
            }
            $y += $altoLinea;
        }

        return $this->guardar($imagen);
    }

    public function logo(string $ruta, int $ancho): ?string
    {
        $datos = app(IdentidadVisual::class)->logoDatos($ruta);
        if ($datos === null) {
            return null;
        }
        $origen = imagecreatefromstring(base64_decode(substr($datos, strpos($datos, ',') + 1)));
        if ($origen === false) {
            return null;
        }
        try {
            $factor = min(1, ($ancho === 58 ? 192 : 256) / imagesx($origen), 120 / imagesy($origen));
            $w = max(1, (int) round(imagesx($origen) * $factor));
            $h = max(1, (int) round(imagesy($origen) * $factor));
            $imagen = $this->lienzo($w, $h);
            imagecopyresampled($imagen, $origen, 0, 0, 0, 0, $w, $h, imagesx($origen), imagesy($origen));

            return $this->guardar($imagen);
        } finally {
            imagedestroy($origen);
        }
    }

    private function ancho(string $texto, string $fuente, int $tamano): int
    {
        $caja = imagettfbbox($tamano, 0, $fuente, $texto);
        if ($caja === false) {
            throw new RuntimeException('No se pudo cargar la tipografía del ticket.');
        }

        return abs($caja[2] - $caja[0]);
    }

    private function lienzo(int $ancho, int $alto): GdImage
    {
        if ($alto < 1 || $ancho < 1 || $alto > 12000) {
            throw new RuntimeException('El documento supera el tamaño permitido para impresión gráfica.');
        }
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefill($imagen, 0, 0, 0xFFFFFF);

        return $imagen;
    }

    private function guardar(GdImage $imagen): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'sisrest-ticket-');
        try {
            if ($ruta === false) {
                throw new RuntimeException('No se pudo crear el archivo temporal del ticket.');
            }
            // GD de escpos-php selecciona el formato por la extensión del archivo.
            $png = $ruta.'.png';
            if (! rename($ruta, $png)) {
                throw new RuntimeException('No se pudo preparar la imagen PNG del ticket.');
            }
            $ruta = $png;
            if (! imagepng($imagen, $ruta)) {
                throw new RuntimeException('No se pudo generar la imagen del ticket.');
            }

            return $ruta;
        } catch (Throwable $error) {
            if ($ruta !== false) {
                @unlink($ruta);
            }
            throw $error;
        } finally {
            imagedestroy($imagen);
        }
    }
}
