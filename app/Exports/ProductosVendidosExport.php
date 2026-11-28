<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductosVendidosExport extends DefaultValueBinder implements
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
            ['REPORTE DE PRODUCTOS VENDIDOS'],

            [$this->datos['nombreEmpresa']],

            ['RUC: '.($this->datos['empresa']?->ruc ?? '—')],

            ['Período: '.$this->datos['periodo']],

            [
                'Categoría: '.$this->datos['categoriaNombre']
                .' | Caja: '.$this->datos['cajaNombre']
            ],

            [
                'Modalidad: '.$this->datos['modalidadNombre']
                .' | Búsqueda: '.$this->datos['buscar']
            ],

            [
                'Generado por: '.$this->datos['usuario']
                .' | '.$this->datos['fechaEmision']
            ],

            [null],

            [
                'Productos',
                'Cantidad neta',
                'Descuentos aplicados',
                'Importe neto',
            ],

            [
                (int) $resumen['productos'],
                (float) $resumen['cantidad_neta'],
                (float) $resumen['descuento'],
                (float) $resumen['importe_neto'],
            ],

            [null],

            [
                'Código',
                'Producto',
                'Categoría',
                'Cobrados',
                'Devueltos',
                'Cantidad neta',
                'Descuento',
                'Importe cobrado',
                'Importe devuelto',
                'Importe neto',
            ],
        ];

        foreach ($this->datos['productos'] as $producto) {
            $filas[] = [
                $producto->codigo,
                $producto->nombre,
                $producto->categoria ?? 'Sin categoría',
                (float) $producto->cantidad_cobrada,
                (float) $producto->cantidad_devuelta,
                (float) $producto->cantidad_neta,
                (float) $producto->descuento,
                (float) $producto->importe_cobrado,
                (float) $producto->importe_devuelto,
                (float) $producto->importe_neto,
            ];
        }

        return $filas;
    }

    public function title(): string
    {
        return 'Productos vendidos';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 20,
            'B' => 35,
            'C' => 25,
            'D' => 20,
            'E' => 16,
            'F' => 18,
            'G' => 18,
            'H' => 20,
            'I' => 20,
            'J' => 20,
        ];
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
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
        $ultimaFila = 12 + $this->datos['productos']->count();

        for ($fila = 1; $fila <= 7; $fila++) {
            $sheet->mergeCells("A{$fila}:J{$fila}");
        }

        $sheet->getRowDimension(1)->setRowHeight(30);

        $formatoMoneda =
            '"S/ " #,##0.00;[Red]"- S/ " #,##0.00';

        $sheet->getStyle('B10')
            ->getNumberFormat()
            ->setFormatCode('#,##0.####');

        $sheet->getStyle('C10:D10')
            ->getNumberFormat()
            ->setFormatCode($formatoMoneda);

        if ($ultimaFila > 12) {
            $sheet->getStyle("D13:F{$ultimaFila}")
                ->getNumberFormat()
                ->setFormatCode('#,##0.####');

            $sheet->getStyle("G13:J{$ultimaFila}")
                ->getNumberFormat()
                ->setFormatCode($formatoMoneda);
        }

        $sheet->setAutoFilter("A12:J{$ultimaFila}");
        $sheet->freezePane('A13');

        $encabezado = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => 'solid',
                'startColor' => ['rgb' => 'EEEEEE'],
            ],
        ];

        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 16,
                ],
            ],

            'A9:D9' => $encabezado,

            'A10:D10' => [
                'font' => [
                    'bold' => true,
                    'size' => 13,
                ],
            ],

            'A12:J12' => $encabezado,
        ];
    }
}
