<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
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

class CajasArqueosExport extends DefaultValueBinder implements
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
            ['REPORTE DE CAJAS Y ARQUEOS'],

            [$this->datos['nombreEmpresa']],

            ['RUC: '.($this->datos['empresa']?->ruc ?? '—')],

            ['Período de apertura: '.$this->datos['periodo']],

            [
                'Caja: '.$this->datos['cajaNombre']
                .' | Estado: '.$this->datos['estadoNombre']
            ],

            ['Búsqueda: '.$this->datos['buscar']],

            [
                'Generado por: '.$this->datos['usuario']
                .' | '.$this->datos['fechaEmision']
            ],

            [null],

            [
                'Turnos',
                'Cobros netos',
                'Faltantes',
                'Sobrantes',
                'Abiertos',
                'Cerrados',
            ],

            [
                (int) $resumen['turnos'],
                (float) $resumen['neto_cobrado'],
                (float) $resumen['faltantes'],
                (float) $resumen['sobrantes'],
                (int) $resumen['abiertas'],
                (int) $resumen['cerradas'],
            ],

            [null],

            [
                'Turno',
                'Caja',
                'Responsable',
                'Apertura',
                'Cierre',
                'Estado',
                'Arqueos parciales',
                'Fondo inicial',
                'Ingresos',
                'Egresos',
                'Retiros',
                'Cobrado',
                'Devuelto',
                'Cobros netos',
                'Efectivo esperado',
                'Efectivo contado',
                'Diferencia',
                'Observaciones de cierre',
            ],
        ];

        foreach ($this->datos['turnos'] as $turno) {

            $cerrada = $turno->estado === 'cerrada';

            $filas[] = [
                (int) $turno->id,
                $turno->caja,
                $turno->responsable,

                Date::dateTimeToExcel(
                    CarbonImmutable::parse($turno->abierta_at)
                ),

                $turno->cerrada_at
                    ? Date::dateTimeToExcel(
                        CarbonImmutable::parse($turno->cerrada_at)
                    )
                    : null,

                $cerrada ? 'Cerrada' : 'Abierta',

                (int) $turno->arqueos_parciales,

                (float) $turno->fondo_inicial,
                (float) ($turno->ingresos ?? 0),
                (float) ($turno->egresos ?? 0),
                (float) ($turno->retiros ?? 0),
                (float) $turno->cobrado,
                (float) $turno->devuelto,
                (float) $turno->neto_cobrado,
                (float) $turno->esperado,

                $cerrada && $turno->contado !== null
                    ? (float) $turno->contado
                    : null,

                $cerrada && $turno->diferencia !== null
                    ? (float) $turno->diferencia
                    : null,

                $cerrada
                    ? $turno->observaciones_cierre
                    : null,
            ];
        }

        return $filas;
    }

    public function title(): string
    {
        return 'Cajas y arqueos';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12,
            'B' => 22,
            'C' => 30,
            'D' => 23,
            'E' => 23,
            'F' => 15,
            'G' => 20,
            'H' => 19,
            'I' => 18,
            'J' => 18,
            'K' => 18,
            'L' => 18,
            'M' => 18,
            'N' => 20,
            'O' => 22,
            'P' => 22,
            'Q' => 18,
            'R' => 48,
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
        $ultimaFila = 12 + $this->datos['turnos']->count();

        for ($fila = 1; $fila <= 7; $fila++) {
            $sheet->mergeCells("A{$fila}:R{$fila}");
        }

        $sheet->getRowDimension(1)->setRowHeight(30);

        $formatoMoneda =
            '"S/ " #,##0.00;[Red]"- S/ " #,##0.00';

        $sheet->getStyle('B10:D10')
            ->getNumberFormat()
            ->setFormatCode($formatoMoneda);

        if ($ultimaFila > 12) {

            $sheet->getStyle("D13:E{$ultimaFila}")
                ->getNumberFormat()
                ->setFormatCode('dd/mm/yyyy hh:mm');

            $sheet->getStyle("H13:Q{$ultimaFila}")
                ->getNumberFormat()
                ->setFormatCode($formatoMoneda);

            $sheet->getStyle("R13:R{$ultimaFila}")
                ->getAlignment()
                ->setWrapText(true);
        }

        $sheet->setAutoFilter("A12:R{$ultimaFila}");

        $sheet->freezePane('D13');

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

            'A9:F9' => $encabezado,

            'A10:F10' => [
                'font' => [
                    'bold' => true,
                    'size' => 13,
                ],
            ],

            'A12:R12' => $encabezado,
        ];
    }
}
