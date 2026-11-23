<?php

namespace App\Services\Reportes;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportadorReportes
{
    /**
     * Genera un reporte PDF con el formato estándar.
     */
    public function pdf(
        string $vista,
        array $datos,
        string $nombreArchivo
    ): StreamedResponse {
        File::ensureDirectoryExists(
            (string) config(
                'dompdf.options.font_cache',
                storage_path('fonts')
            )
        );

        $pdf = Pdf::loadView($vista, $datos)
            ->setOption('isRemoteEnabled', false)
            ->setPaper('a4', 'landscape');

        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();

        $fuente = $dompdf->getFontMetrics()
            ->getFont('DejaVu Sans', 'normal');

        $canvas->page_text(
            $canvas->get_width() - 135,
            $canvas->get_height() - 24,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $fuente,
            9,
            [0.35, 0.35, 0.35]
        );

        return $this->descargar(
            $pdf->output(),
            $nombreArchivo,
            'application/pdf'
        );
    }

    /**
     * Genera un archivo XLSX utilizando una clase Export.
     */
    public function excel(
        object $exportacion,
        string $nombreArchivo
    ): StreamedResponse {
        $contenido = Excel::raw(
            $exportacion,
            \Maatwebsite\Excel\Excel::XLSX
        );

        return $this->descargar(
            $contenido,
            $nombreArchivo,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    /**
     * Centraliza la respuesta de descarga.
     */
    private function descargar(
        string $contenido,
        string $nombreArchivo,
        string $tipo
    ): StreamedResponse {
        return response()->streamDownload(
            static function () use ($contenido): void {
                echo $contenido;
            },
            $nombreArchivo,
            [
                'Content-Type' => $tipo,
                'Cache-Control' => 'private, no-store',
            ]
        );
    }
}
