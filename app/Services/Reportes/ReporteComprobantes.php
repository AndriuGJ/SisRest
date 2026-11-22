<?php

namespace App\Services\Reportes;

use App\Models\Comprobante;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReporteComprobantes
{
    public function autorizar(User $actor): void
    {
        abort_unless(
            $actor->activo
            && $actor->can('reportes.ver_todos')
            && $actor->can('comprobantes.ver'),
            403
        );
    }

    public function tipos(): array
    {
        return [
            '01' => 'Factura',
            '03' => 'Boleta',
        ];
    }

    public function estadosSunat(): array
    {
        return [
            'pendiente' => 'Pendiente',
            'enviado' => 'Enviado',
            'aceptado' => 'Aceptado',
            'observado' => 'Observado',
            'rechazado' => 'Rechazado',
            'desconocido' => 'Desconocido',
        ];
    }

    public function validarFiltros(
        User $actor,
        array $filtros
    ): array {
        $this->autorizar($actor);

        $hoy = CarbonImmutable::now(
            config('app.timezone')
        )->toDateString();

        $datos = Validator::make($filtros, [
            'desde' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:'.$hoy,
            ],
            'hasta' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:desde',
                'before_or_equal:'.$hoy,
            ],
            'buscar' => [
                'nullable',
                'string',
                'max:100',
            ],
            'tipo' => [
                'nullable',
                Rule::in(array_keys($this->tipos())),
            ],
            'estado' => [
                'nullable',
                Rule::in(['emitido', 'anulado']),
            ],
            'sunat' => [
                'nullable',
                Rule::in(array_keys($this->estadosSunat())),
            ],
            'entorno' => [
                'nullable',
                Rule::in(['beta', 'produccion']),
            ],
        ])->validate();

        $inicio = CarbonImmutable::parse(
            $datos['desde'],
            config('app.timezone')
        )->startOfDay();

        $fin = CarbonImmutable::parse(
            $datos['hasta'],
            config('app.timezone')
        )->startOfDay();

        if ($inicio->diffInDays($fin) >= 90) {
            throw ValidationException::withMessages([
                'fechas' => 'Selecciona un período máximo de 90 días.',
            ]);
        }

        $datos['buscar'] = trim(
            $datos['buscar'] ?? ''
        );

        return $datos;
    }

    public function consulta(
        User $actor,
        array $filtros
    ): Builder {
        $this->autorizar($actor);

        $consulta = Comprobante::query()
            ->with('serie.tipoComprobante')
            ->whereBetween('fecha_emision', [
                $filtros['desde'],
                $filtros['hasta'],
            ])
            ->where('moneda', 'PEN');

        if (! empty($filtros['tipo'])) {
            $consulta->whereHas(
                'serie.tipoComprobante',
                fn (Builder $q) => $q->where(
                    'codigo',
                    $filtros['tipo']
                )
            );
        }

        if (! empty($filtros['estado'])) {
            $consulta->where(
                'estado',
                $filtros['estado']
            );
        }

        if (! empty($filtros['sunat'])) {
            $consulta->where(
                'estado_sunat',
                $filtros['sunat']
            );
        }

        if (! empty($filtros['entorno'])) {
            $consulta->where(
                'entorno',
                $filtros['entorno']
            );
        }

        $buscar = $filtros['buscar'];

        if ($buscar !== '') {
            $consulta->where(function (Builder $q) use ($buscar) {

                $q->where(
                    'receptor->nombre',
                    'like',
                    '%'.$buscar.'%'
                )
                    ->orWhere(
                        'receptor->numero',
                        'like',
                        '%'.$buscar.'%'
                    )
                    ->orWhereHas(
                        'serie',
                        fn (Builder $serie) =>
                            $serie->where(
                                'serie',
                                'like',
                                '%'.$buscar.'%'
                            )
                    );

                if (ctype_digit($buscar)) {
                    $q->orWhere(
                        'correlativo',
                        (int) $buscar
                    );
                }

                // Permite buscar F001-123 o B001-00000123.
                if (preg_match(
                    '/^([A-Z0-9]{4})-(\d{1,8})$/i',
                    $buscar,
                    $coincidencias
                )) {
                    $serieBuscada = strtoupper($coincidencias[1]);
                    $numeroBuscado = (int) $coincidencias[2];

                    $q->orWhere(function (Builder $documento) use (
                        $serieBuscada,
                        $numeroBuscado
                    ) {
                        $documento
                            ->where('correlativo', $numeroBuscado)
                            ->whereHas(
                                'serie',
                                fn (Builder $serie) =>
                                    $serie->where(
                                        'serie',
                                        $serieBuscada
                                    )
                            );
                    });
                }
            });
        }

        return $consulta;
    }

    public function resumen(Builder $consulta): array
    {
        $totales = (clone $consulta)
            ->reorder()
            ->toBase()
            ->selectRaw("
                COUNT(*) AS documentos,

                COALESCE(SUM(
                    CASE WHEN estado_sunat = 'aceptado'
                    THEN 1 ELSE 0 END
                ), 0) AS aceptados,

                COALESCE(SUM(
                    CASE WHEN estado_sunat = 'observado'
                    THEN 1 ELSE 0 END
                ), 0) AS observados,

                COALESCE(SUM(
                    CASE WHEN estado_sunat IN (
                        'pendiente',
                        'enviado',
                        'desconocido'
                    )
                    THEN 1 ELSE 0 END
                ), 0) AS pendientes,

                COALESCE(SUM(
                    CASE WHEN estado_sunat = 'rechazado'
                    THEN 1 ELSE 0 END
                ), 0) AS rechazados,

                COALESCE(SUM(
                    CASE
                        WHEN entorno = 'produccion'
                        AND estado = 'emitido'
                        THEN total ELSE 0
                    END
                ), 0) AS importe_produccion,

                COALESCE(SUM(
                    CASE
                        WHEN entorno = 'beta'
                        AND estado = 'emitido'
                        THEN total ELSE 0
                    END
                ), 0) AS importe_beta
            ")
            ->first();

        return [
            'documentos' => (int) $totales->documentos,
            'aceptados' => (int) $totales->aceptados,
            'observados' => (int) $totales->observados,
            'pendientes' => (int) $totales->pendientes,
            'rechazados' => (int) $totales->rechazados,
            'importe_produccion' => (string) $totales->importe_produccion,
            'importe_beta' => (string) $totales->importe_beta,
        ];
    }
}
