<?php

namespace App\Services\Reportes;

use App\Enums\EstadoCobro;
use App\Enums\EstadoDevolucion;
use App\Models\Caja;
use App\Models\User;
use App\Models\Cobro;
use App\Models\Devolucion;
use App\Services\Cajas\AccesoCajas;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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



public function validarFiltros(User $actor, array $filtros): array
{
    $this->autorizar($actor);

    $hoy = CarbonImmutable::now(config('app.timezone'));

    $cajas = $this->cajas($actor);

    $validados = Validator::make($filtros, [
        'desde' => [
            'required',
            'date_format:Y-m-d',
        ],

        'hasta' => [
            'required',
            'date_format:Y-m-d',
            'after_or_equal:desde',
            'before_or_equal:'.$hoy->toDateString(),
        ],

        'caja' => [
            'nullable',
            'integer',
            Rule::in($cajas->pluck('id')->all()),
        ],

        'modalidad' => [
            'nullable',
            Rule::in(['mesa', 'rapida', 'delivery']),
        ],

        'tipo' => [
            'nullable',
            Rule::in(['cobro', 'devolucion']),
        ],

        'buscar' => [
            'nullable',
            'string',
        ],

    ])->validate();

    $inicio = CarbonImmutable::parse(
        $validados['desde'],
        config('app.timezone')
    )->startOfDay();

    $hasta = CarbonImmutable::parse(
        $validados['hasta'],
        config('app.timezone')
    )->startOfDay();

    if ($inicio->diffInDays($hasta) >= 90) {
        throw ValidationException::withMessages([
            'fechas' => 'Selecciona un período máximo de 90 días.',
        ]);
    }

    $validados['inicio'] = $inicio;
    $validados['fin'] = $hasta->endOfDay();

    $validados['buscar'] = trim(
        mb_substr($validados['buscar'] ?? '', 0, 100)
    );

    return $validados;
}


public function consulta(User $actor, array $filtros): Builder
{
    $this->autorizar($actor);

    // Cobros confirmados.
    $cobros = Cobro::query()
        ->join('pedidos as p', 'p.id', '=', 'cobros.pedido_id')
        ->join('turnos_caja as t', 't.id', '=', 'cobros.turno_caja_id')
        ->join('cajas as caja', 'caja.id', '=', 't.caja_id')
        ->where('cobros.estado', EstadoCobro::CONFIRMADO->value)
        ->where('cobros.moneda', 'PEN')
        ->whereBetween('cobros.cobrado_at', [
            $filtros['inicio'],
            $filtros['fin'],
        ])
        ->selectRaw("
            cobros.id AS operacion_id,
            cobros.codigo AS codigo,
            cobros.codigo AS codigo_cobro,
            p.codigo AS pedido,
            cobros.cliente_nombre AS cliente,
            cobros.cobrado_at AS fecha,
            'cobro' AS tipo,
            p.modalidad AS modalidad,
            COALESCE(cobros.caja_nombre, caja.nombre) AS caja,
            cobros.total AS importe
        ");

    // Devoluciones confirmadas.
    $devoluciones = Devolucion::query()
        ->join('cobros as c', 'c.id', '=', 'devoluciones.cobro_id')
        ->join('pedidos as p', 'p.id', '=', 'c.pedido_id')
        ->join('turnos_caja as t', 't.id', '=', 'devoluciones.turno_caja_id')
        ->join('cajas as caja', 'caja.id', '=', 't.caja_id')
        ->where('devoluciones.estado', EstadoDevolucion::CONFIRMADA->value)
        ->where('c.moneda', 'PEN')
        ->whereBetween('devoluciones.devuelta_at', [
            $filtros['inicio'],
            $filtros['fin'],
        ])
        ->selectRaw("
            devoluciones.id AS operacion_id,
            devoluciones.codigo AS codigo,
            c.codigo AS codigo_cobro,
            p.codigo AS pedido,
            c.cliente_nombre AS cliente,
            devoluciones.devuelta_at AS fecha,
            'devolucion' AS tipo,
            p.modalidad AS modalidad,
            COALESCE(devoluciones.caja_nombre, caja.nombre) AS caja,
            -devoluciones.total AS importe
        ");

    // Restringir a los turnos de caja autorizados.
    if (! $actor->can('reportes.ver_todos')) {
        $turnos = AccesoCajas::consulta($actor)->select('id');

        $cobros->whereIn(
            'cobros.turno_caja_id',
            clone $turnos
        );

        $devoluciones->whereIn(
            'devoluciones.turno_caja_id',
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

    // Unificar los resultados de ambos modelos.
    $operaciones = $cobros->toBase()->unionAll(
        $devoluciones->toBase()
    );

    $consulta = DB::query()->fromSub(
        $operaciones,
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
        $consulta->where(function (Builder $q) use ($buscar) {
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
