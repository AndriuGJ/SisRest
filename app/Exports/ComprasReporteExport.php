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

class ComprasReporteExport extends DefaultValueBinder implements
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
            ['REPORTE DE COMPRAS'],

            [$this->datos['nombreEmpresa']],

            ['RUC: '.(
                $this->datos['empresa']?->ruc ?? '—'
            )],

            ['Período: '.$this->datos['periodo']],

            [
                'Proveedor: '.$this->datos['proveedorNombre']
                .' | Estado: '.$this->datos['estadoNombre']
            ],

            [
                'Pago: '.$this->datos['pagoNombre']
                .' | Búsqueda: '.$this->datos['buscar']
            ],

            [
                'Generado por: '.$this->datos['usuario']
                .' | '.$this->datos['fechaEmision']
            ],

            [null],

            [
                'Compras',
                'Confirmadas',
                'Total confirmado',
                'Pagado actualmente',
                'Saldo pendiente',
                'Vencidas',
            ],

            [
                (int) $resumen['compras'],
                (int) $resumen['confirmadas'],
                (float) $resumen['total_confirmado'],
                (float) $resumen['pagado'],
                (float) $resumen['pendiente'],
                (int) $resumen['vencidas'],
            ],

            [null],

            [
                'Fecha',
                'Código',
                'Proveedor',
                'Documento',
                'Condición',
                'Vencimiento',
                'Estado',
                'Total',
                'Pagado',
                'Pendiente',
                'Situación de pago',
            ],
        ];

        foreach ($this->datos['compras'] as $compra) {

            $confirmada = $compra->estado === 'confirmada';

            $saldo = $confirmada
                ? (string) $compra->pendiente
                : null;

            $pagada = $confirmada
                && bccomp($saldo, '0', 2) === 0;

            $vencida = $confirmada
                && $compra->condicion_pago === 'credito'
                && $compra->fecha_vencimiento
                && $compra->fecha_vencimiento < $this->datos['hoy']
                && bccomp($saldo, '0', 2) > 0;

            $situacion = !$confirmada
                ? 'No aplica'
                : ($pagada
                    ? 'Pagada'
                    : ($vencida ? 'Vencida' : 'Pendiente'));

            $documento = '';

            if ($compra->numero) {
                $numero = (
                    $compra->serie
                        ? $compra->serie.'-'
                        : ''
                ).$compra->numero;

                $documento = trim(
                    ($compra->documento_nombre ?? 'Documento')
                    .' '.$numero
                );
            }

            $filas[] = [
                Date::dateTimeToExcel(
                    CarbonImmutable::parse($compra->fecha)
                ),

                $compra->codigo,
                $compra->proveedor,
                $documento,

                $compra->condicion_pago === 'credito'
                    ? 'Crédito'
                    : 'Contado',

                $compra->fecha_vencimiento
                    ? Date::dateTimeToExcel(
                        CarbonImmutable::parse(
                            $compra->fecha_vencimiento
                        )
                    )
                    : null,

                ucfirst($compra->estado),

                (float) $compra->total,

                $confirmada
                    ? (float) $compra->pagado
                    : null,

                $confirmada
                    ? (float) $saldo
                    : null,

                $situacion,
            ];
        }

        return $filas;
    }

    public function title(): string
    {
        return 'Compras';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 17,
            'B' => 34,
            'C' => 36,
            'D' => 30,
            'E' => 17,
            'F' => 19,
            'G' => 17,
            'H' => 20,
            'I' => 20,
            'J' => 20,
            'K' => 23,
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
            12 + $this->datos['compras']->count()
        );

        for ($fila = 1; $fila <= 7; $fila++) {
            $sheet->mergeCells("A{$fila}:K{$fila}");
        }

        $sheet->getRowDimension(1)->setRowHeight(30);

        $formatoMoneda =
            '"S/ " #,##0.00;[Red]"- S/ " #,##0.00';

        $sheet->getStyle('C10:E10')
            ->getNumberFormat()
            ->setFormatCode($formatoMoneda);

        if ($ultimaFila > 12) {
            $sheet->getStyle("A13:A{$ultimaFila}")
                ->getNumberFormat()
                ->setFormatCode('dd/mm/yyyy');

            $sheet->getStyle("F13:F{$ultimaFila}")
                ->getNumberFormat()
                ->setFormatCode('dd/mm/yyyy');

            $sheet->getStyle("H13:J{$ultimaFila}")
                ->getNumberFormat()
                ->setFormatCode($formatoMoneda);
        }

        $sheet->setAutoFilter(
            "A12:K{$ultimaFila}"
        );

        $sheet->freezePane('C13');

        $encabezado = [
            'font' => ['bold' => true],

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

            'A9:F9' => $encabezado,

            'A10:F10' => [
                'font' => [
                    'bold' => true,
                    'size' => 13,
                ],
            ],

            'A12:K12' => $encabezado,
        ];
    }
}
