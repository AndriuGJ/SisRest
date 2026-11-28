<?php

namespace App\Exports;

use App\Services\Facturacion\XmlFiscal;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ComprobantesReporteExport extends DefaultValueBinder implements
    FromArray,
    WithStyles,
    WithTitle,
    WithColumnWidths,
    WithCustomValueBinder,
    WithStrictNullComparison
{
    public function __construct(
        private array $datos
    ) {}

    public function array(): array
    {
        $resumen = $this->datos['resumen'];

        $filas = [
            ['REPORTE DE COMPROBANTES'],

            [$this->datos['nombreEmpresa']],

            [
                'RUC: '.(
                    $this->datos['empresa']?->ruc ?? '—'
                )
            ],

            ['Período: '.$this->datos['periodo']],

            [
                'Tipo: '.$this->datos['tipoNombre']
                .' | Estado: '.$this->datos['estadoNombre']
            ],

            [
                'SUNAT: '.$this->datos['sunatNombre']
                .' | Entorno: '.$this->datos['entornoNombre']
                .' | Búsqueda: '.$this->datos['buscar']
            ],

            [
                'Generado por: '.$this->datos['usuario']
                .' | '.$this->datos['fechaEmision']
            ],

            [null],

            [
                'Documentos',
                'Aceptados',
                'Observados',
                'Por resolver',
                'Rechazados',
                'Importe Producción',
                'Importe Beta',
            ],

            [
                (int) $resumen['documentos'],
                (int) $resumen['aceptados'],
                (int) $resumen['observados'],
                (int) $resumen['pendientes'],
                (int) $resumen['rechazados'],
                (float) $resumen['importe_produccion'],
                (float) $resumen['importe_beta'],
            ],

            [null],

            [
                'Fecha',
                'Tipo',
                'Número',
                'Cliente',
                'DNI / RUC',
                'Entorno',
                'Estado documento',
                'Estado SUNAT',
                'Valor de venta',
                'Descuento',
                'Impuestos',
                'Operaciones gratuitas',
                'Total',
            ],
        ];

        foreach ($this->datos['comprobantes'] as $comprobante) {
            $codigoTipo = XmlFiscal::dato(
                $comprobante,
                'tipo'
            );

            $tipoNombre = $this->datos['tipos'][
                $codigoTipo
            ] ?? 'Comprobante';

            $estadoSunat = $comprobante->estado_sunat->value;

            $filas[] = [
                Date::dateTimeToExcel(
                    $comprobante->fecha_emision
                ),

                $tipoNombre,

                XmlFiscal::numero($comprobante),

                $comprobante->receptor['nombre']
                    ?? 'Sin nombre',

                (string) (
                    $comprobante->receptor['numero']
                    ?? ''
                ),

                $comprobante->entorno === 'beta'
                    ? 'Beta'
                    : 'Producción',

                ucfirst(
                    $comprobante->estado->value
                ),

                $this->datos['estadosSunat'][
                    $estadoSunat
                ] ?? ucfirst($estadoSunat),

                (float) $comprobante->valor_venta,
                (float) $comprobante->descuento,
                (float) $comprobante->impuestos,
                (float) $comprobante->operaciones_gratuitas,
                (float) $comprobante->total,
            ];
        }

        return $filas;
    }

    public function title(): string
    {
        return 'Comprobantes';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 17,
            'B' => 17,
            'C' => 23,
            'D' => 36,
            'E' => 20,
            'F' => 18,
            'G' => 23,
            'H' => 22,
            'I' => 20,
            'J' => 19,
            'K' => 19,
            'L' => 24,
            'M' => 20,
        ];
    }

    public function bindValue(
        Cell $cell,
        mixed $value
    ): bool {
        if (is_string($value)) {
            $cell->setValueExplicit(
                $value,
                DataType::TYPE_STRING
            );

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function styles(Worksheet $sheet): array
    {
        $ultimaFila = max(
            12,
            12 + $this->datos['comprobantes']->count()
        );

        for ($fila = 1; $fila <= 7; $fila++) {
            $sheet->mergeCells(
                "A{$fila}:M{$fila}"
            );
        }

        $sheet->getRowDimension(1)
            ->setRowHeight(30);

        $formatoMoneda =
            '"S/ " #,##0.00;[Red]"- S/ " #,##0.00';

        $sheet->getStyle('F10:G10')
            ->getNumberFormat()
            ->setFormatCode($formatoMoneda);

        if ($ultimaFila > 12) {
            $sheet->getStyle("A13:A{$ultimaFila}")
                ->getNumberFormat()
                ->setFormatCode('dd/mm/yyyy');

            $sheet->getStyle("I13:M{$ultimaFila}")
                ->getNumberFormat()
                ->setFormatCode($formatoMoneda);
        }

        $sheet->setAutoFilter(
            "A12:M{$ultimaFila}"
        );

        $sheet->freezePane('D13');

        $encabezado = [
            'font' => [
                'bold' => true,
            ],
            'fill' => [
                'fillType' => 'solid',
                'startColor' => [
                    'rgb' => 'EEEEEE',
                ],
            ],
        ];

        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 16,
                ],
            ],

            'A9:G9' => $encabezado,

            'A10:G10' => [
                'font' => [
                    'bold' => true,
                    'size' => 13,
                ],
            ],

            'A12:M12' => $encabezado,
        ];
    }
}
