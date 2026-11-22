<?php

namespace App\Services\Reportes;

use App\Enums\EstadoCobro;
use App\Enums\EstadoDevolucion;
use App\Models\CategoriaProducto;
use App\Models\CobroDetalle;
use App\Models\DevolucionDetalle;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReporteProductos
{
    public function __construct(
        private ReporteVentas $ventas
    ) {}

    public function categorias()
    {
        return CategoriaProducto::query()
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
    }

    public function validarFiltros(
        User $actor,
        array $filtros
    ): array {
        $validados = $this->ventas->validarFiltros(
            $actor,
            $filtros
        );

        $categoria = Validator::make($filtros, [
            'categoria' => [
                'nullable',
                'integer',
                Rule::in($this->categorias()->pluck('id')->all()),
            ],
        ])->validate();

        $validados['categoria'] = $categoria['categoria'] ?? null;

        return $validados;
    }

    public function consulta(
        User $actor,
        array $filtros
    ): Builder {
        $this->ventas->autorizar($actor);

        // Productos cobrados.
        $cobros = CobroDetalle::query()
            ->join('cobros as c', 'c.id', '=', 'cobro_detalles.cobro_id')
            ->join('pedido_detalles as pd', 'pd.id', '=', 'cobro_detalles.pedido_detalle_id')
            ->join('pedidos as p', 'p.id', '=', 'c.pedido_id')
            ->join('turnos_caja as t', 't.id', '=', 'c.turno_caja_id')
            ->where('c.estado', EstadoCobro::CONFIRMADO->value)
            ->where('c.moneda', 'PEN')
            ->whereNull('pd.detalle_padre_id')
            ->whereBetween('c.cobrado_at', [
                $filtros['inicio'],
                $filtros['fin'],
            ])
            ->selectRaw('
                pd.producto_id,
                SUM(cobro_detalles.cantidad) AS cantidad_cobrada,
                0 AS cantidad_devuelta,
                SUM(cobro_detalles.descuento) AS descuento,
                SUM(cobro_detalles.total) AS importe_cobrado,
                0 AS importe_devuelto
            ')
            ->groupBy('pd.producto_id');

        // Productos devueltos.
        $devoluciones = DevolucionDetalle::query()
            ->join('devoluciones as d', 'd.id', '=', 'devolucion_detalles.devolucion_id')
            ->join('cobro_detalles as cd', 'cd.id', '=', 'devolucion_detalles.cobro_detalle_id')
            ->join('cobros as c', 'c.id', '=', 'cd.cobro_id')
            ->join('pedido_detalles as pd', 'pd.id', '=', 'cd.pedido_detalle_id')
            ->join('pedidos as p', 'p.id', '=', 'c.pedido_id')
            ->join('turnos_caja as t', 't.id', '=', 'd.turno_caja_id')
            ->where('d.estado', EstadoDevolucion::CONFIRMADA->value)
            ->where('c.moneda', 'PEN')
            ->whereNull('pd.detalle_padre_id')
            ->whereBetween('d.devuelta_at', [
                $filtros['inicio'],
                $filtros['fin'],
            ])
            ->selectRaw('
                pd.producto_id,
                0 AS cantidad_cobrada,
                SUM(devolucion_detalles.cantidad) AS cantidad_devuelta,
                0 AS descuento,
                0 AS importe_cobrado,
                SUM(devolucion_detalles.importe) AS importe_devuelto
            ')
            ->groupBy('pd.producto_id');

        // Respetar los turnos autorizados.
        if (! $actor->can('reportes.ver_todos')) {
            $turnos = AccesoCajas::consulta($actor)->select('id');

            $cobros->whereIn(
                'c.turno_caja_id',
                clone $turnos
            );

            $devoluciones->whereIn(
                'd.turno_caja_id',
                clone $turnos
            );
        }

        // Filtros comunes.
        if (! empty($filtros['caja'])) {
            $cobros->where('t.caja_id', $filtros['caja']);
            $devoluciones->where('t.caja_id', $filtros['caja']);
        }

        if (! empty($filtros['modalidad'])) {
            $cobros->where('p.modalidad', $filtros['modalidad']);
            $devoluciones->where('p.modalidad', $filtros['modalidad']);
        }

        // Unificar los resultados.
        $operaciones = $cobros->toBase()
            ->unionAll($devoluciones->toBase());

        $consulta = DB::query()
            ->fromSub($operaciones, 'movimientos')
            ->join(
                'productos as producto',
                'producto.id',
                '=',
                'movimientos.producto_id'
            )
            ->leftJoin(
                'categorias_producto as categoria',
                'categoria.id',
                '=',
                'producto.categoria_producto_id'
            )
            ->selectRaw('
                producto.id AS producto_id,
                producto.codigo,
                producto.nombre,
                categoria.nombre AS categoria,

                SUM(movimientos.cantidad_cobrada) AS cantidad_cobrada,

                SUM(movimientos.cantidad_devuelta) AS cantidad_devuelta,

                SUM(movimientos.cantidad_cobrada)
                    - SUM(movimientos.cantidad_devuelta) AS cantidad_neta,

                SUM(movimientos.descuento) AS descuento,

                SUM(movimientos.importe_cobrado) AS importe_cobrado,

                SUM(movimientos.importe_devuelto) AS importe_devuelto,

                SUM(movimientos.importe_cobrado)
                    - SUM(movimientos.importe_devuelto) AS importe_neto
            ')
            ->groupBy(
                'producto.id',
                'producto.codigo',
                'producto.nombre',
                'categoria.nombre'
            );

        // Filtro por categoría.
        if (! empty($filtros['categoria'])) {
            $consulta->where(
                'producto.categoria_producto_id',
                $filtros['categoria']
            );
        }

        // Búsqueda por nombre o código.
        $buscar = trim(
            mb_substr($filtros['buscar'] ?? '', 0, 100)
        );

        if ($buscar !== '') {
            $consulta->where(function (Builder $q) use ($buscar) {
                $q->where('producto.nombre', 'like', '%'.$buscar.'%')
                    ->orWhere('producto.codigo', 'like', '%'.$buscar.'%');
            });
        }

        return $consulta;
    }

    public function resumen(Builder $consulta): array
    {
        $totales = DB::query()
            ->fromSub(clone $consulta, 'resumen_productos')
            ->selectRaw('
                COUNT(*) AS productos,
                COALESCE(SUM(cantidad_neta), 0) AS cantidad_neta,
                COALESCE(SUM(descuento), 0) AS descuento,
                COALESCE(SUM(importe_neto), 0) AS importe_neto
            ')
            ->first();

        return [
            'productos' => (int) $totales->productos,
            'cantidad_neta' => (string) $totales->cantidad_neta,
            'descuento' => (string) $totales->descuento,
            'importe_neto' => (string) $totales->importe_neto,
        ];
    }
}
