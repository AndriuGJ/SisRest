<?php

namespace App\Services\Dashboard;

use App\Enums\EstadoCobro;
use App\Enums\EstadoDevolucion;
use App\Models\Articulo;
use App\Models\Cobro;
use App\Models\Devolucion;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Pos\AccesoPos;
use Carbon\CarbonImmutable;
use App\Models\CobroDetalle;
use App\Models\Pago;

class EstadisticasDashboard
{
    public function obtener(User $actor): array
    {
        $hoy = CarbonImmutable::now(config('app.timezone'));
        $inicio = $hoy->startOfDay();
        $fin = $hoy->endOfDay();
        $seleccion = request()->query('periodo', '7');

        $periodo = in_array($seleccion, ['7', '30'], true)
            ? (int) $seleccion
            : 7;

        $verCobros = $actor->can('reportes.ver_todos')
            || (
                $actor->can('reportes.ver_propios')
                && (
                    $actor->can('cajas.ver_propias')
                    || $actor->can('cajas.ver_todas')
                )
            );

        $verPedidos = $actor->can('pos.ver')
            && (
                $actor->can('pedidos.ver_todos')
                || $actor->can('pedidos.ver_propios')
            );

        $verCajas = $actor->can('cajas.ver_todas')
            || $actor->can('cajas.ver_propias');

        $verInventario = $actor->can('inventario.ver');

        $datos = [
            'verCobros' => $verCobros,
            'verPedidos' => $verPedidos,
            'verCajas' => $verCajas,
            'verInventario' => $verInventario,

            'periodo' => $periodo,

            'cobrosNetosHoy' => '0.00',
            'cantidadCobros' => 0,
            'pedidosActivos' => 0,
            'cajasAbiertas' => 0,
            'stockBajo' => 0,

            'grafico' => [
                'etiquetas' => [],
                'valores' => [],
            ],

            'productosMasCobrados' => [
                'etiquetas' => [],
                'valores' => [],
            ],

            'mediosPago' => [
                'etiquetas' => [],
                'valores' => [],
            ],
        ];

        // Pedidos visibles para el usuario.
        if ($verPedidos) {
            $datos['pedidosActivos'] = AccesoPos::consulta($actor)
                ->whereNotIn('estado', ['cerrado', 'anulado'])
                ->count();
        }

        // Cajas abiertas autorizadas.
        if ($verCajas) {
            $datos['cajasAbiertas'] = AccesoCajas::consulta($actor)
                ->where('estado', 'abierta')
                ->count();
        }

        // Artículos activos con existencias bajas.
        if ($verInventario) {
            $datos['stockBajo'] = Articulo::query()
                ->where('activo', true)
                ->where('stock_minimo', '>', 0)
                ->whereRaw(
                    'stock_actual - stock_reservado <= stock_minimo'
                )
                ->count();
        }

        // No consultar importes si el usuario no tiene permiso.
        if (! $verCobros) {
            return $datos;
        }

        $cobros = Cobro::query()
            ->where('estado', EstadoCobro::CONFIRMADO->value);

        $devoluciones = Devolucion::query()
            ->where('estado', EstadoDevolucion::CONFIRMADA->value);

        // Los reportes propios solo consultan cajas autorizadas.
        if (! $actor->can('reportes.ver_todos')) {
            $cobros->whereIn(
                'turno_caja_id',
                AccesoCajas::consulta($actor)->select('id')
            );

            $devoluciones->whereIn(
                'turno_caja_id',
                AccesoCajas::consulta($actor)->select('id')
            );
        }

        $cobradoHoy = (string) (clone $cobros)
            ->whereBetween('cobrado_at', [$inicio, $fin])
            ->sum('total');

        $devueltoHoy = (string) (clone $devoluciones)
            ->whereBetween('devuelta_at', [$inicio, $fin])
            ->sum('total');

        $datos['cobrosNetosHoy'] = bcsub(
            $cobradoHoy,
            $devueltoHoy,
            2
        );

        $datos['cantidadCobros'] = (clone $cobros)
            ->whereBetween('cobrado_at', [$inicio, $fin])
            ->count();

        // Gráfico de los últimos siete días.
        $primerDia = $hoy->subDays($periodo - 1)->startOfDay();

        $ventasPorDia = (clone $cobros)
            ->whereBetween('cobrado_at', [$primerDia, $fin])
            ->selectRaw(
                'DATE(cobrado_at) AS dia, SUM(total) AS importe'
            )
            ->groupByRaw('DATE(cobrado_at)')
            ->pluck('importe', 'dia');

        $devolucionesPorDia = (clone $devoluciones)
            ->whereBetween('devuelta_at', [$primerDia, $fin])
            ->selectRaw(
                'DATE(devuelta_at) AS dia, SUM(total) AS importe'
            )
            ->groupByRaw('DATE(devuelta_at)')
            ->pluck('importe', 'dia');

        for ($i = 0; $i < $periodo; $i++) {
            $dia = $primerDia->addDays($i);
            $fecha = $dia->format('Y-m-d');

            $neto = bcsub(
                (string) ($ventasPorDia[$fecha] ?? '0'),
                (string) ($devolucionesPorDia[$fecha] ?? '0'),
                2
            );

            $datos['grafico']['etiquetas'][] =
                $dia->format('d/m');

            $datos['grafico']['valores'][] =
                (float) $neto;
        }

        // ==========================================
        // TOP 5 PRODUCTOS COBRADOS
        // ==========================================

        $productos = CobroDetalle::query()
            ->join(
                'cobros as c',
                'c.id',
                '=',
                'cobro_detalles.cobro_id'
            )
            ->join(
                'pedido_detalles as pd',
                'pd.id',
                '=',
                'cobro_detalles.pedido_detalle_id'
            )
            ->where('c.estado', EstadoCobro::CONFIRMADO->value)
            ->whereBetween('c.cobrado_at', [$primerDia, $fin])
            ->whereNull('pd.detalle_padre_id')
            ->when(
                ! $actor->can('reportes.ver_todos'),
                fn ($q) => $q->whereIn(
                    'c.turno_caja_id',
                    AccesoCajas::consulta($actor)->select('id')
                )
            )
            ->selectRaw(
                'pd.producto_id,
                 MAX(cobro_detalles.descripcion) AS nombre,
                 SUM(cobro_detalles.cantidad) AS unidades'
            )
            ->groupBy('pd.producto_id')
            ->orderByDesc('unidades')
            ->limit(5)
            ->get();

        foreach ($productos as $producto) {
            $datos['productosMasCobrados']['etiquetas'][] =
                $producto->nombre;

            $datos['productosMasCobrados']['valores'][] =
                (float) $producto->unidades;
        }

        // ==========================================
        // PAGOS RECIBIDOS POR MEDIO
        // ==========================================

        $pagos = Pago::query()
            ->join(
                'cobros as c',
                'c.id',
                '=',
                'pagos.cobro_id'
            )
            ->where('c.estado', EstadoCobro::CONFIRMADO->value)
            ->whereBetween('c.cobrado_at', [$primerDia, $fin])
            ->when(
                ! $actor->can('reportes.ver_todos'),
                fn ($q) => $q->whereIn(
                    'c.turno_caja_id',
                    AccesoCajas::consulta($actor)->select('id')
                )
            )
            ->selectRaw(
                'pagos.medio_pago_id,
                 MAX(pagos.medio_pago_nombre) AS nombre,
                 SUM(pagos.monto) AS total'
            )
            ->groupBy('pagos.medio_pago_id')
            ->orderByDesc('total')
            ->get();

        foreach ($pagos as $pago) {
            $datos['mediosPago']['etiquetas'][] =
                $pago->nombre ?: 'Medio de pago #'.$pago->medio_pago_id;

            $datos['mediosPago']['valores'][] =
                (float) $pago->total;
        }


        return $datos;
    }
}
