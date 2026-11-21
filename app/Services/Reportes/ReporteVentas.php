<?php

namespace App\Services\Reportes;

use App\Enums\EstadoCobro;
use App\Enums\EstadoDevolucion;
use App\Models\Caja;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReporteVentas
{
    public function autorizar(User $actor): void
    {
        abort_unless(
            $actor->activo && (
                $actor->can('reportes.ver_todos')
                || (
                    $actor->can('reportes.ver_propios')
                    && (
                        $actor->can('cajas.ver_propias')
                        || $actor->can('cajas.ver_todas')
                    )
                )
            ),
            403
        );
    }

    public function cajas(User $actor)
    {
        $this->autorizar($actor);

        return Caja::query()
            ->when(
                ! $actor->can('reportes.ver_todos'),
                fn ($q) => $q->whereIn(
                    'id',
                    AccesoCajas::consulta($actor)
                        ->select('caja_id')
                )
            )
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
    }

    public function consulta(
        User $actor,
        array $filtros
    ): Builder {
        $this->autorizar($actor);

        // Cobros confirmados.
        $cobros = DB::table('cobros as c')
            ->join('pedidos as p', 'p.id', '=', 'c.pedido_id')
            ->join('turnos_caja as t', 't.id', '=', 'c.turno_caja_id')
            ->join('cajas as caja', 'caja.id', '=', 't.caja_id')
            ->where('c.estado', EstadoCobro::CONFIRMADO->value)
            ->where('c.moneda', 'PEN')
            ->whereBetween('c.cobrado_at', [
                $filtros['inicio'],
                $filtros['fin'],
            ])
            ->selectRaw("
                c.id AS operacion_id,
                c.codigo AS codigo,
                c.codigo AS codigo_cobro,
                p.codigo AS pedido,
                c.cliente_nombre AS cliente,
                c.cobrado_at AS fecha,
                'cobro' AS tipo,
                p.modalidad AS modalidad,
                COALESCE(c.caja_nombre, caja.nombre) AS caja,
                c.total AS importe
            ");

        // Devoluciones confirmadas.
        $devoluciones = DB::table('devoluciones as d')
            ->join('cobros as c', 'c.id', '=', 'd.cobro_id')
            ->join('pedidos as p', 'p.id', '=', 'c.pedido_id')
            ->join('turnos_caja as t', 't.id', '=', 'd.turno_caja_id')
            ->join('cajas as caja', 'caja.id', '=', 't.caja_id')
            ->where('d.estado', EstadoDevolucion::CONFIRMADA->value)
            ->where('c.moneda', 'PEN')
            ->whereBetween('d.devuelta_at', [
                $filtros['inicio'],
                $filtros['fin'],
            ])
            ->selectRaw("
                d.id AS operacion_id,
                d.codigo AS codigo,
                c.codigo AS codigo_cobro,
                p.codigo AS pedido,
                c.cliente_nombre AS cliente,
                d.devuelta_at AS fecha,
                'devolucion' AS tipo,
                p.modalidad AS modalidad,
                COALESCE(d.caja_nombre, caja.nombre) AS caja,
                -d.total AS importe
            ");

        // Restringir las operaciones a los turnos autorizados.
        if (! $actor->can('reportes.ver_todos')) {

            $turnos = AccesoCajas::consulta($actor)
                ->select('id')
                ->toBase();

            $cobros->whereIn(
                'c.turno_caja_id',
                clone $turnos
            );

            $devoluciones->whereIn(
                'd.turno_caja_id',
                clone $turnos
            );
        }

        // Filtro por caja.
        if (! empty($filtros['caja'])) {
            $cobros->where('t.caja_id', $filtros['caja']);
            $devoluciones->where('t.caja_id', $filtros['caja']);
        }

        // Filtro por modalidad.
        if (! empty($filtros['modalidad'])) {
            $cobros->where('p.modalidad', $filtros['modalidad']);
            $devoluciones->where('p.modalidad', $filtros['modalidad']);
        }

        // Unificar ambos tipos de operaciones.
        $consulta = DB::query()->fromSub(
            $cobros->unionAll($devoluciones),
            'operaciones'
        );

        // Filtro por tipo de operación.
        if (! empty($filtros['tipo'])) {
            $consulta->where('tipo', $filtros['tipo']);
        }

        // Búsqueda por código, pedido o cliente.
        $buscar = trim(
            mb_substr($filtros['buscar'] ?? '', 0, 100)
        );

        if ($buscar !== '') {
            $consulta->where(function ($q) use ($buscar) {
                $q->where('codigo', 'like', '%'.$buscar.'%')
                    ->orWhere('codigo_cobro', 'like', '%'.$buscar.'%')
                    ->orWhere('pedido', 'like', '%'.$buscar.'%')
                    ->orWhere('cliente', 'like', '%'.$buscar.'%');
            });
        }

        return $consulta;
    }

    public function resumen(Builder $consulta): array
    {
        $totales = (clone $consulta)
            ->selectRaw("
                COALESCE(SUM(
                    CASE WHEN tipo = 'cobro'
                    THEN importe ELSE 0 END
                ), 0) AS cobrado,

                COALESCE(SUM(
                    CASE WHEN tipo = 'devolucion'
                    THEN -importe ELSE 0 END
                ), 0) AS devuelto,

                COALESCE(SUM(importe), 0) AS neto,

                COUNT(*) AS operaciones
            ")
            ->first();

        return [
            'cobrado' => (string) $totales->cobrado,
            'devuelto' => (string) $totales->devuelto,
            'neto' => (string) $totales->neto,
            'operaciones' => (int) $totales->operaciones,
        ];
    }
}
