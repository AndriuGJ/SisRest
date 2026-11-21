<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VentasCobrosExport extends DefaultValueBinder implements
    FromArray,
    WithStyles,
    WithTitle,
    WithColumnWidths,
    WithCustomValueBinder,
    WithStrictNullComparison
{
    public function __construct(
        private Collection $operaciones,
        private array $datos,
        private array $modalidades
    ) {}

    public function array(): array
    {
        $cobrado = '0.00';
        $devuelto = '0.00';
        $detalle = [];

        foreach ($this->operaciones as $operacion) {

            if ($operacion->tipo === 'cobro') {
                $cobrado = bcadd(
                    $cobrado,
                    (string) $operacion->importe,
                    2
                );
            } else {
                $devuelto = bcsub(
                    $devuelto,
                    (string) $operacion->importe,
                    2
                );
            }

            $detalle[] = [
                Date::dateTimeToExcel(
                    CarbonImmutable::parse($operacion->fecha)
                ),

                $operacion->codigo,
                $operacion->codigo_cobro,
                $operacion->pedido,
                $operacion->cliente ?: 'Sin cliente',

                $operacion->tipo === 'cobro'
                    ? 'Cobro'
                    : 'Devolución',

                $this->modalidades[$operacion->modalidad]
                    ?? 'Otra',

                $operacion->caja,

                (float) $operacion->importe,
            ];
        }

        return array_merge([
            ['REPORTE DE VENTAS Y COBROS'],

            [$this->datos['empresa']],

            ['RUC: '.$this->datos['ruc']],

            ['Período: '.$this->datos['periodo']],

            [
                'Caja: '.$this->datos['caja']
                .' | Modalidad: '.$this->datos['modalidad']
            ],

            [
                'Operación: '.$this->datos['tipo']
                .' | Búsqueda: '.$this->datos['buscar']
            ],

            ['Generado por: '.$this->datos['usuario']
                .' | '.$this->datos['fecha']],

            [null],

            [
                'Total cobrado',
                'Devoluciones',
                'Cobros netos',
                'Operaciones',
            ],

            [
                (float) $cobrado,
                (float) $devuelto,
                (float) bcsub($cobrado, $devuelto, 2),
                $this->operaciones->count(),
            ],

            [null],

            [
                'Fecha y hora',
                'Código',
                'Código de cobro',
                'Pedido',
                'Cliente',
                'Operación',
                'Modalidad',
                'Caja',
                'Importe',
            ],

        ], $detalle);
    }

    public function title(): string
    {
        return 'Ventas y cobros';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 23,
            'B' => 26,
            'C' => 26,
            'D' => 26,
            'E' => 32,
            'F' => 17,
            'G' => 19,
            'H' => 20,
            'I' => 20,
        ];
    }

    public function bindValue(
        Cell $cell,
        mixed $value
    ): bool {
        // Conservar códigos y nombres como texto.
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
            12 + $this->operaciones->count()
        );

        for ($fila = 1; $fila <= 7; $fila++) {
            $sheet->mergeCells("A{$fila}:I{$fila}");
        }

        $sheet->getRowDimension(1)->setRowHeight(30);

        $sheet->getStyle('A10:C10')
            ->getNumberFormat()
            ->setFormatCode('"S/ " #,##0.00');

        if ($ultimaFila > 12) {

            $sheet->getStyle("A13:A{$ultimaFila}")
                ->getNumberFormat()
                ->setFormatCode('dd/mm/yyyy hh:mm');

            $sheet->getStyle("I13:I{$ultimaFila}")
                ->getNumberFormat()
                ->setFormatCode(
                    '"S/ " #,##0.00;[Red]"- S/ " #,##0.00'
                );
        }

        $sheet->setAutoFilter("A12:I{$ultimaFila}");

        $sheet->freezePane('A13');

        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 16,
                ],
            ],

            'A9:D9' => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => 'solid',
                    'startColor' => [
                        'rgb' => 'EEEEEE',
                    ],
                ],
            ],

            'A10:D10' => [
                'font' => [
                    'bold' => true,
                    'size' => 13,
                ],
            ],

            'A12:I12' => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => 'solid',
                    'startColor' => [
                        'rgb' => 'EEEEEE',
                    ],
                ],
            ],
        ];
    }
}
