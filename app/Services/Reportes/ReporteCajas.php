<?php

namespace App\Services\Reportes;

use App\Models\Cobro;
use App\Models\Devolucion;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReporteCajas
{
    public function __construct(
        private ReporteVentas $ventas
    ) {}

    public function validarFiltros(
        User $actor,
        array $filtros
    ): array {
        $validados = $this->ventas->validarFiltros(
            $actor,
            $filtros
        );

        $estado = Validator::make($filtros, [
            'estado' => [
                'nullable',
                'string',
                Rule::in(['abierta', 'cerrada']),
            ],
        ])->validate();

        $validados['estado'] = $estado['estado'] ?? null;

        return $validados;
    }

    public function consulta(
        User $actor,
        array $filtros
    ): Builder {
        $this->ventas->autorizar($actor);

        $turnos = $actor->can('reportes.ver_todos')
            ? TurnoCaja::query()
            : AccesoCajas::consulta($actor);

        $turnos
            ->select([
                'turnos_caja.id',
                'turnos_caja.estado',
                'turnos_caja.fondo_inicial',
                'turnos_caja.abierta_at',
                'turnos_caja.cerrada_at',
            ])
            ->join(
                'cajas as caja',
                'caja.id',
                '=',
                'turnos_caja.caja_id'
            )
            ->join(
                'users as responsable',
                'responsable.id',
                '=',
                'turnos_caja.usuario_id'
            )
            ->leftJoin('arqueos_caja as cierre', function ($join) {
                $join->on(
                    'cierre.turno_caja_id',
                    '=',
                    'turnos_caja.id'
                )->where('cierre.tipo', 'cierre');
            })
            ->selectRaw('
                COALESCE(turnos_caja.caja_nombre, caja.nombre) AS caja,
                COALESCE(turnos_caja.responsable_nombre, responsable.name) AS responsable,

                cierre.efectivo_esperado AS esperado_cierre,
                cierre.efectivo_contado AS contado,
                cierre.diferencia AS diferencia,
                cierre.observaciones AS observaciones_cierre
            ')
            ->whereBetween('turnos_caja.abierta_at', [
                $filtros['inicio'],
                $filtros['fin'],
            ]);

        // Movimientos físicos del efectivo.
        $turnos
            ->withSum([
                'movimientos as ingresos' => fn ($q) =>
                    $q->where('tipo', 'ingreso'),
            ], 'monto')

            ->withSum([
                'movimientos as egresos' => fn ($q) =>
                    $q->where('tipo', 'egreso'),
            ], 'monto')

            ->withSum([
                'movimientos as retiros' => fn ($q) =>
                    $q->where('tipo', 'retiro'),
            ], 'monto')

            ->withCount([
                'arqueos as arqueos_parciales' => fn ($q) =>
                    $q->where('tipo', 'parcial'),
            ]);

        // Cobros confirmados del turno completo.
        $turnos->selectSub(
            Cobro::query()
                ->whereColumn(
                    'cobros.turno_caja_id',
                    'turnos_caja.id'
                )
                ->where('estado', 'confirmado')
                ->where('moneda', 'PEN')
                ->selectRaw('COALESCE(SUM(total), 0)'),
            'cobrado'
        );

        // Devoluciones efectuadas durante el turno.
        $turnos->selectSub(
            Devolucion::query()
                ->whereColumn(
                    'devoluciones.turno_caja_id',
                    'turnos_caja.id'
                )
                ->where('estado', 'confirmada')
                ->whereHas('cobro', fn ($q) =>
                    $q->where('moneda', 'PEN')
                )
                ->selectRaw('COALESCE(SUM(total), 0)'),
            'devuelto'
        );

        // Filtro por caja.
        if (! empty($filtros['caja'])) {
            $turnos->where(
                'turnos_caja.caja_id',
                $filtros['caja']
            );
        }

        // Filtro por estado.
        if (! empty($filtros['estado'])) {
            $turnos->where(
                'turnos_caja.estado',
                $filtros['estado']
            );
        }

        // Búsqueda por caja o responsable.
        $buscar = $filtros['buscar'] ?? '';

        if ($buscar !== '') {
            $turnos->where(function ($q) use ($buscar) {
                $q->where('turnos_caja.caja_nombre', 'like', '%'.$buscar.'%')
                    ->orWhere('turnos_caja.responsable_nombre', 'like', '%'.$buscar.'%')
                    ->orWhere('caja.nombre', 'like', '%'.$buscar.'%')
                    ->orWhere('caja.codigo', 'like', '%'.$buscar.'%')
                    ->orWhere('responsable.name', 'like', '%'.$buscar.'%');
            });
        }

        // Calcular saldos sobre los resultados de Eloquent.
        return DB::query()
            ->fromSub($turnos->toBase(), 'turnos')
            ->selectRaw('
                turnos.*,

                cobrado - devuelto AS neto_cobrado,

                CASE
                    WHEN estado = \'cerrada\'
                        AND esperado_cierre IS NOT NULL
                    THEN esperado_cierre
                    ELSE fondo_inicial
                        + COALESCE(ingresos, 0)
                        - COALESCE(egresos, 0)
                        - COALESCE(retiros, 0)
                END AS esperado
            ');
    }

    public function resumen(Builder $consulta): array
    {
        $totales = DB::query()
            ->fromSub(clone $consulta, 'reporte')
            ->selectRaw("
                COUNT(*) AS turnos,

                COALESCE(SUM(
                    CASE WHEN estado = 'abierta'
                    THEN 1 ELSE 0 END
                ), 0) AS abiertas,

                COALESCE(SUM(
                    CASE WHEN estado = 'cerrada'
                    THEN 1 ELSE 0 END
                ), 0) AS cerradas,

                COALESCE(SUM(neto_cobrado), 0)
                    AS neto_cobrado,

                COALESCE(SUM(
                    CASE
                        WHEN estado = 'cerrada'
                            AND diferencia < 0
                        THEN -diferencia
                        ELSE 0
                    END
                ), 0) AS faltantes,

                COALESCE(SUM(
                    CASE
                        WHEN estado = 'cerrada'
                            AND diferencia > 0
                        THEN diferencia
                        ELSE 0
                    END
                ), 0) AS sobrantes
            ")
            ->first();

        return [
            'turnos' => (int) $totales->turnos,
            'abiertas' => (int) $totales->abiertas,
            'cerradas' => (int) $totales->cerradas,
            'neto_cobrado' => (string) $totales->neto_cobrado,
            'faltantes' => (string) $totales->faltantes,
            'sobrantes' => (string) $totales->sobrantes,
        ];
    }
}
