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
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventarioKardexExport extends DefaultValueBinder implements
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
        $existencias = $this->datos['vista'] === 'existencias';
        $verCostos = $this->datos['verCostos'];
        $resumen = $this->datos['resumen'];

        $encabezados = $existencias
            ? [
                'Código',
                'Artículo',
                'Tipo',
                'Unidad',
                'Actual',
                'Reservado',
                'Disponible',
                'Mínimo',
                'Estado',
            ]
            : [
                'Fecha',
                'Código',
                'Artículo',
                'Unidad',
                'Movimiento',
                'Origen',
                'Entrada',
                'Salida',
                'Saldo anterior',
                'Saldo posterior',
            ];

        if ($verCostos) {
            array_push(
                $encabezados,
                ...($existencias
                    ? ['Costo promedio', 'Valorización']
                    : ['Costo unitario'])
            );
        }

        $resumenTitulos = $existencias
            ? ['Artículos', 'Bajo el mínimo', 'Sin disponible']
            : ['Movimientos', 'Entradas', 'Salidas', 'Artículos afectados'];

        $resumenValores = $existencias
            ? [
                (int) $resumen['articulos'],
                (int) $resumen['bajo_minimo'],
                (int) $resumen['sin_disponible'],
            ]
            : [
                (int) $resumen['movimientos'],
                (int) $resumen['entradas'],
                (int) $resumen['salidas'],
                (int) $resumen['articulos'],
            ];

        if ($existencias && $verCostos) {
            $resumenTitulos[] = 'Valorización actual';
            $resumenValores[] = (float) $resumen['valorizacion'];
        }

        $filas = [
            [$this->datos['titulo']],
            [$this->datos['nombreEmpresa']],
            ['RUC: '.($this->datos['empresa']?->ruc ?? '—')],

            [
                $existencias
                    ? 'Existencias al: '.$this->datos['fechaEmision']
                    : 'Período: '.$this->datos['periodo']
            ],

            ['Tipo de artículo: '.$this->datos['tipoArticuloNombre']],

            [
                ($existencias ? 'Disponibilidad: ' : 'Movimiento: ')
                .$this->datos['filtroNombre']
                .' | Búsqueda: '.$this->datos['buscar']
            ],

            [
                'Generado por: '.$this->datos['usuario']
                .' | '.$this->datos['fechaEmision']
            ],

            [null],

            $resumenTitulos,
            $resumenValores,

            [null],

            $encabezados,
        ];

        foreach ($this->datos['registros'] as $registro) {

            if ($existencias) {
                $fila = [
                    $registro->codigo,
                    $registro->nombre,

                    $this->datos['tiposArticulo'][
                        $registro->tipo->value
                    ] ?? 'Artículo',

                    $registro->unidadMedida->codigo,

                    (float) $registro->stock_actual,
                    (float) $registro->stock_reservado,
                    (float) $registro->disponible,
                    (float) $registro->stock_minimo,

                    $registro->activo ? 'Activo' : 'Inactivo',
                ];

                if ($verCostos) {
                    $fila[] = (float) $registro->costo_promedio;

                    $fila[] = (float) bcmul(
                        $registro->stock_actual,
                        $registro->costo_promedio,
                        6
                    );
                }

            } else {

                $entrada = bccomp(
                    $registro->cantidad, '0', 4
                ) > 0;

                $magnitud = $entrada
                    ? $registro->cantidad
                    : bcsub('0', $registro->cantidad, 4);

                $origen = match ($registro->origen_type) {
                    \App\Models\Compra::class => 'Compra',
                    \App\Models\Preparacion::class => 'Preparación',
                    \App\Models\Pedido::class => 'Pedido',
                    default => 'Movimiento',
                };

                if ($registro->origen_id) {
                    $origen .= ' #'.$registro->origen_id;
                }

                $fila = [
                    Date::dateTimeToExcel(
                        $registro->registrado_at
                    ),

                    $registro->articulo->codigo,
                    $registro->articulo->nombre,
                    $registro->articulo->unidadMedida->codigo,

                    $this->datos['tiposMovimiento'][
                        $registro->tipo->value
                    ] ?? $registro->tipo->value,

                    $origen,

                    $entrada ? (float) $magnitud : null,
                    $entrada ? null : (float) $magnitud,

                    (float) $registro->saldo_anterior,
                    (float) $registro->saldo_posterior,
                ];

                if ($verCostos) {
                    $fila[] = (float) $registro->costo_unitario;
                }
            }

            $filas[] = $fila;
        }

        return $filas;
    }

    public function title(): string
    {
        return $this->datos['vista'] === 'existencias'
            ? 'Existencias'
            : 'Kardex';
    }

    public function columnWidths(): array
    {
        if ($this->datos['vista'] === 'existencias') {
            return [
                'A' => 20,
                'B' => 36,
                'C' => 20,
                'D' => 15,
                'E' => 18,
                'F' => 18,
                'G' => 18,
                'H' => 18,
                'I' => 17,
                'J' => 20,
                'K' => 22,
            ];
        }

        return [
            'A' => 24,
            'B' => 20,
            'C' => 34,
            'D' => 15,
            'E' => 26,
            'F' => 24,
            'G' => 17,
            'H' => 17,
            'I' => 19,
            'J' => 19,
            'K' => 21,
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
        $existencias = $this->datos['vista'] === 'existencias';
        $verCostos = $this->datos['verCostos'];

        $ultimaColumna = $existencias
            ? ($verCostos ? 'K' : 'I')
            : ($verCostos ? 'K' : 'J');

        $ultimaFila = 12 + $this->datos['registros']->count();

        for ($fila = 1; $fila <= 7; $fila++) {
            $sheet->mergeCells(
                "A{$fila}:{$ultimaColumna}{$fila}"
            );
        }

        $sheet->getRowDimension(1)->setRowHeight(30);

        $formatoCantidad = '#,##0.####';
        $formatoCosto = '#,##0.000000';
        $formatoMoneda =
            '"S/ " #,##0.00;[Red]"- S/ " #,##0.00';

        if ($existencias && $verCostos) {
            $sheet->getStyle('D10')
                ->getNumberFormat()
                ->setFormatCode($formatoMoneda);
        }

        if ($ultimaFila > 12) {

            if ($existencias) {

                $sheet->getStyle("E13:H{$ultimaFila}")
                    ->getNumberFormat()
                    ->setFormatCode($formatoCantidad);

                if ($verCostos) {
                    $sheet->getStyle("J13:J{$ultimaFila}")
                        ->getNumberFormat()
                        ->setFormatCode($formatoCosto);

                    $sheet->getStyle("K13:K{$ultimaFila}")
                        ->getNumberFormat()
                        ->setFormatCode($formatoMoneda);
                }

            } else {

                $sheet->getStyle("A13:A{$ultimaFila}")
                    ->getNumberFormat()
                    ->setFormatCode('dd/mm/yyyy hh:mm');

                $sheet->getStyle("G13:J{$ultimaFila}")
                    ->getNumberFormat()
                    ->setFormatCode($formatoCantidad);

                if ($verCostos) {
                    $sheet->getStyle("K13:K{$ultimaFila}")
                        ->getNumberFormat()
                        ->setFormatCode($formatoCosto);
                }
            }
        }

        $sheet->setAutoFilter(
            "A12:{$ultimaColumna}{$ultimaFila}"
        );

        $sheet->freezePane('C13');

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

            "A12:{$ultimaColumna}12" => $encabezado,
        ];
    }
}
