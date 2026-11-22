<?php

namespace App\Services\Reportes;

use App\Models\Compra;
use App\Models\PagoCompra;
use App\Models\Proveedor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReporteCompras
{
    public function autorizar(User $actor): void
    {
        abort_unless(
            $actor->activo
            && $actor->can('reportes.ver_todos')
            && $actor->can('compras.ver'),
            403
        );
    }

    public function proveedores()
    {
        return Proveedor::query()
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
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
            'proveedor' => [
                'nullable',
                'integer',
                Rule::exists('proveedores', 'id'),
            ],
            'estado' => [
                'nullable',
                Rule::in([
                    'borrador',
                    'confirmada',
                    'anulada',
                ]),
            ],
            'pago' => [
                'nullable',
                Rule::in([
                    'pendiente',
                    'pagada',
                    'vencida',
                ]),
            ],
            'buscar' => [
                'nullable',
                'string',
                'max:100',
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

        $datos['buscar'] = trim($datos['buscar'] ?? '');

        return $datos;
    }

    public function consulta(
        User $actor,
        array $filtros
    ): Builder {
        $this->autorizar($actor);

        $base = Compra::query()
            ->leftJoin(
                'proveedores as pr',
                'pr.id',
                '=',
                'compras.proveedor_id'
            )
            ->select([
                'compras.id',
                'compras.codigo',
                'compras.proveedor_id',
                'compras.fecha',
                'compras.fecha_vencimiento',
                'compras.estado',
                'compras.condicion_pago',
                'compras.documento_nombre',
                'compras.serie',
                'compras.numero',
                'compras.total',
            ])
            ->selectRaw(
                'COALESCE(compras.proveedor_nombre, pr.nombre) AS proveedor'
            )
            ->selectSub(
                PagoCompra::query()
                    ->whereColumn(
                        'pagos_compra.compra_id',
                        'compras.id'
                    )
                    ->selectRaw(
                        'COALESCE(SUM(pagos_compra.monto), 0)'
                    ),
                'pagado'
            )
            ->whereBetween('compras.fecha', [
                $filtros['desde'],
                $filtros['hasta'],
            ]);

        if (! empty($filtros['proveedor'])) {
            $base->where(
                'compras.proveedor_id',
                $filtros['proveedor']
            );
        }

        if (! empty($filtros['estado'])) {
            $base->where(
                'compras.estado',
                $filtros['estado']
            );
        }

        if ($filtros['buscar'] !== '') {
            $buscar = $filtros['buscar'];

            $base->where(function ($q) use ($buscar) {
                $q->where('compras.codigo', 'like', '%'.$buscar.'%')
                    ->orWhere('compras.proveedor_nombre', 'like', '%'.$buscar.'%')
                    ->orWhere('pr.nombre', 'like', '%'.$buscar.'%')
                    ->orWhere('compras.serie', 'like', '%'.$buscar.'%')
                    ->orWhere('compras.numero', 'like', '%'.$buscar.'%')
                    ->orWhere('compras.proveedor_documento', 'like', '%'.$buscar.'%');
            });
        }

        $consulta = DB::query()
            ->fromSub($base->toBase(), 'registro')
            ->selectRaw("
                registro.*,

                CASE
                    WHEN registro.estado = 'confirmada'
                    THEN registro.total - registro.pagado
                    ELSE NULL
                END AS pendiente
            ");

        $pago = $filtros['pago'] ?? null;

        if ($pago !== null) {
            $consulta->where(
                'registro.estado',
                'confirmada'
            );

            if ($pago === 'pendiente') {
                $consulta->whereRaw(
                    '(registro.total - registro.pagado) > 0'
                );
            }

            if ($pago === 'pagada') {
                $consulta->whereRaw(
                    '(registro.total - registro.pagado) = 0'
                );
            }

            if ($pago === 'vencida') {
                $hoy = CarbonImmutable::now(
                    config('app.timezone')
                )->toDateString();

                $consulta
                    ->where('registro.condicion_pago', 'credito')
                    ->where('registro.fecha_vencimiento', '<', $hoy)
                    ->whereRaw(
                        '(registro.total - registro.pagado) > 0'
                    );
            }
        }

        return $consulta;
    }

    public function resumen(Builder $consulta): array
    {
        $hoy = CarbonImmutable::now(
            config('app.timezone')
        )->toDateString();

        $totales = DB::query()
            ->fromSub(clone $consulta, 'r')
            ->selectRaw("
                COUNT(*) AS compras,

                COALESCE(SUM(
                    CASE WHEN r.estado = 'confirmada'
                    THEN 1 ELSE 0 END
                ), 0) AS confirmadas,

                COALESCE(SUM(
                    CASE WHEN r.estado = 'confirmada'
                    THEN r.total ELSE 0 END
                ), 0) AS total_confirmado,

                COALESCE(SUM(
                    CASE WHEN r.estado = 'confirmada'
                    THEN r.pagado ELSE 0 END
                ), 0) AS pagado,

                COALESCE(SUM(
                    CASE WHEN r.estado = 'confirmada'
                    THEN r.pendiente ELSE 0 END
                ), 0) AS pendiente,

                COALESCE(SUM(
                    CASE
                        WHEN r.estado = 'confirmada'
                        AND r.condicion_pago = 'credito'
                        AND r.fecha_vencimiento < ?
                        AND r.pendiente > 0
                        THEN 1 ELSE 0
                    END
                ), 0) AS vencidas
            ", [$hoy])
            ->first();

        return [
            'compras' => (int) $totales->compras,
            'confirmadas' => (int) $totales->confirmadas,
            'total_confirmado' => (string) $totales->total_confirmado,
            'pagado' => (string) $totales->pagado,
            'pendiente' => (string) $totales->pendiente,
            'vencidas' => (int) $totales->vencidas,
        ];
    }
}
