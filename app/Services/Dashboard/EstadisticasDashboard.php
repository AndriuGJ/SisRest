<?php

namespace App\Services\Dashboard;

use App\Enums\EstadoCobro;
use App\Enums\EstadoDevolucion;
use App\Models\Articulo;
use App\Models\Cobro;
use App\Models\CobroDetalle;
use App\Models\Devolucion;
use App\Models\Pago;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Pos\AccesoPos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

        $desdeParametro = request()->query('desde');
        $hastaParametro = request()->query('hasta');

        $personalizado = false;
        $errorFechas = null;

        $primerDia = $hoy->subDays($periodo - 1)->startOfDay();
        $finRango = $fin;

        if ($desdeParametro !== null || $hastaParametro !== null) {
            $filtro = [
                'desde' => is_string($desdeParametro) ? $desdeParametro : '',
                'hasta' => is_string($hastaParametro) ? $hastaParametro : '',
            ];

            $validador = Validator::make($filtro, [
                'desde' => ['required', 'date_format:Y-m-d'],
                'hasta' => [
                    'required',
                    'date_format:Y-m-d',
                    'after_or_equal:desde',
                    'before_or_equal:today',
                ],
            ]);

            if ($validador->passes()) {
                $desdeFecha = CarbonImmutable::parse(
                    $filtro['desde'],
                    config('app.timezone')
                )->startOfDay();

                $hastaFecha = CarbonImmutable::parse(
                    $filtro['hasta'],
                    config('app.timezone')
                )->startOfDay();

                $dias = (int) $desdeFecha->diffInDays($hastaFecha) + 1;

                if ($dias <= 90) {
                    $personalizado = true;
                    $periodo = $dias;
                    $primerDia = $desdeFecha;
                    $finRango = $hastaFecha->endOfDay();
                } else {
                    $errorFechas = 'Selecciona un rango de máximo 90 días.';
                }
            } else {
                $errorFechas = 'Selecciona fechas válidas, en orden y sin incluir días futuros.';
            }
        }

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

            'personalizado' => $personalizado,
            'desde' => $personalizado ? $primerDia->toDateString() : '',
            'hasta' => $personalizado ? $finRango->toDateString() : '',
            'errorFechas' => $errorFechas,

            'etiquetaPeriodo' => $personalizado
                ? 'del '.$primerDia->format('d/m/Y').' al '.$finRango->format('d/m/Y')
                : 'de los últimos '.$periodo.' días',

            'comparacion' => [
                'actual' => '0.00',
                'anterior' => '0.00',
                'diferencia' => '0.00',
            ],

            'periodoAnterior' => '',

            'ultimosCobros' => [],

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

            'cobrosPorCaja' => [
                'etiquetas' => [],
                'valores' => [],
            ],

            'cobrosPorModalidad' => [
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

        $ventasPorDia = (clone $cobros)
            ->whereBetween('cobrado_at', [$primerDia, $finRango])
            ->selectRaw(
                'DATE(cobrado_at) AS dia, SUM(total) AS importe'
            )
            ->groupByRaw('DATE(cobrado_at)')
            ->pluck('importe', 'dia');

        $devolucionesPorDia = (clone $devoluciones)
            ->whereBetween('devuelta_at', [$primerDia, $finRango])
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
            ->whereBetween('c.cobrado_at', [$primerDia, $finRango])
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
            ->whereBetween('c.cobrado_at', [$primerDia, $finRango])
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

        // ==========================================
        // COMPARACIÓN CON EL PERÍODO ANTERIOR
        // ==========================================

        $inicioAnterior = $primerDia->subDays($periodo);
        $finAnterior = $primerDia->subSecond();

        $cobradoPeriodo = (string) (clone $cobros)
            ->whereBetween('cobrado_at', [$primerDia, $finRango])
            ->sum('total');

        $devueltoPeriodo = (string) (clone $devoluciones)
            ->whereBetween('devuelta_at', [$primerDia, $finRango])
            ->sum('total');

        $cobradoAnterior = (string) (clone $cobros)
            ->whereBetween('cobrado_at', [$inicioAnterior, $finAnterior])
            ->sum('total');

        $devueltoAnterior = (string) (clone $devoluciones)
            ->whereBetween('devuelta_at', [$inicioAnterior, $finAnterior])
            ->sum('total');

        $netoActual = bcsub($cobradoPeriodo, $devueltoPeriodo, 2);
        $netoAnterior = bcsub($cobradoAnterior, $devueltoAnterior, 2);

        $datos['comparacion'] = [
            'actual' => $netoActual,
            'anterior' => $netoAnterior,
            'diferencia' => bcsub($netoActual, $netoAnterior, 2),
        ];

        $datos['periodoAnterior'] =
            $inicioAnterior->format('d/m/Y')
            .' al '.
            $finAnterior->format('d/m/Y');

        // ==========================================
        // ÚLTIMOS CINCO COBROS DEL PERÍODO
        // ==========================================

        $datos['ultimosCobros'] = (clone $cobros)
            ->whereBetween('cobrado_at', [$primerDia, $finRango])
            ->orderByDesc('cobrado_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get([
                'codigo',
                'cobrado_at',
                'caja_nombre',
                'cliente_nombre',
                'total',
            ])
            ->map(fn (Cobro $cobro): array => [
                'codigo' => $cobro->codigo,
                'fecha' => $cobro->cobrado_at?->format('d/m/Y H:i') ?? '—',
                'caja' => $cobro->caja_nombre ?: '—',
                'cliente' => $cobro->cliente_nombre ?: 'Sin identificar',
                'total' => $cobro->total,
            ])
            ->all();

        // ==========================================
        // COBROS NETOS POR CAJA
        // ==========================================

        $operacionesCaja = (clone $cobros)
            ->whereBetween('cobrado_at', [$primerDia, $finRango])
            ->selectRaw('turno_caja_id, total AS importe')
            ->toBase()
            ->unionAll(
                (clone $devoluciones)
                    ->whereBetween('devuelta_at', [$primerDia, $finRango])
                    ->selectRaw('turno_caja_id, -total AS importe')
                    ->toBase()
            );

        $resumenCajas = DB::query()
            ->fromSub($operacionesCaja, 'operaciones')
            ->join(
                'turnos_caja as t',
                't.id',
                '=',
                'operaciones.turno_caja_id'
            )
            ->join(
                'cajas as caja',
                'caja.id',
                '=',
                't.caja_id'
            )
            ->selectRaw(
                'caja.id,
         caja.nombre,
         SUM(operaciones.importe) AS total'
            )
            ->groupBy('caja.id', 'caja.nombre')
            ->orderByDesc('total')
            ->get();

        foreach ($resumenCajas as $caja) {
            $datos['cobrosPorCaja']['etiquetas'][] =
                $caja->nombre;

            $datos['cobrosPorCaja']['valores'][] =
                (float) $caja->total;
        }

        // ==========================================
        // COBROS POR MODALIDAD DE ATENCIÓN
        // ==========================================

        $cobrosDelPeriodo = (clone $cobros)
            ->whereBetween('cobrado_at', [$primerDia, $finRango])
            ->select(['pedido_id', 'total']);

        $resumenModalidades = DB::query()
            ->fromSub($cobrosDelPeriodo, 'c')
            ->join(
                'pedidos as p',
                'p.id',
                '=',
                'c.pedido_id'
            )
            ->selectRaw(
                'p.modalidad, SUM(c.total) AS total'
            )
            ->groupBy('p.modalidad')
            ->havingRaw('SUM(c.total) > 0')
            ->orderByDesc('total')
            ->get();

        foreach ($resumenModalidades as $modalidad) {
            $nombre = match ($modalidad->modalidad) {
                'mesa' => 'Mesas',
                'rapida' => 'Venta rápida',
                'delivery' => 'Delivery',
                default => 'Otros',
            };

            $datos['cobrosPorModalidad']['etiquetas'][] =
                $nombre;

            $datos['cobrosPorModalidad']['valores'][] =
                (float) $modalidad->total;
        }

        return $datos;
    }
}
