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

class EstadisticasDashboard
{
    public function obtener(User $actor): array
    {
        $hoy = CarbonImmutable::now(config('app.timezone'));
        $inicio = $hoy->startOfDay();
        $fin = $hoy->endOfDay();

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
            'cobrosNetosHoy' => '0.00',
            'cantidadCobros' => 0,
            'pedidosActivos' => 0,
            'cajasAbiertas' => 0,
            'stockBajo' => 0,
            'grafico' => [
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
        $primerDia = $hoy->subDays(6)->startOfDay();

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

        for ($i = 0; $i < 7; $i++) {
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

        return $datos;
    }
}
