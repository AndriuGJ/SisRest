<?php

namespace App\Services\Reportes;

use App\Enums\TipoMovimientoInventario;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReporteInventario
{
    public function autorizar(User $actor): void
    {
        abort_unless(
            $actor->activo
            && $actor->can('reportes.ver_todos')
            && $actor->can('inventario.ver'),
            403
        );
    }

    public function tiposMovimiento(): array
    {
        return [
            'compra' => 'Compra',
            'consumo_preparacion' => 'Consumo de preparación',
            'produccion' => 'Producción',
            'entrega' => 'Entrega',
            'reingreso' => 'Reingreso',
            'merma' => 'Merma',
            'consumo_interno' => 'Consumo interno',
            'ajuste' => 'Ajuste',
        ];
    }

    public function tiposArticulo(): array
    {
        return [
            'insumo' => 'Insumos',
            'preparado' => 'Preparados',
            'reventa' => 'Reventa',
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
            'vista' => [
                'required',
                Rule::in(['existencias', 'kardex']),
            ],
            'buscar' => [
                'nullable',
                'string',
                'max:100',
            ],
            'tipo_articulo' => [
                'nullable',
                Rule::in(array_keys($this->tiposArticulo())),
            ],
            'disponibilidad' => [
                'nullable',
                Rule::in(['bajo', 'agotado', 'con_stock']),
            ],
            'tipo_movimiento' => [
                'nullable',
                Rule::in(
                    array_column(
                        TipoMovimientoInventario::cases(),
                        'value'
                    )
                ),
            ],
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
        ])->validate();

        $datos['buscar'] = trim($datos['buscar'] ?? '');

        $inicio = CarbonImmutable::parse(
            $datos['desde'],
            config('app.timezone')
        )->startOfDay();

        $fin = CarbonImmutable::parse(
            $datos['hasta'],
            config('app.timezone')
        )->endOfDay();

        if (
            $datos['vista'] === 'kardex'
            && $inicio->diffInDays($fin->startOfDay()) >= 90
        ) {
            throw ValidationException::withMessages([
                'fechas' => 'Selecciona un período máximo de 90 días.',
            ]);
        }

        $datos['inicio'] = $inicio;
        $datos['fin'] = $fin;

        return $datos;
    }

    public function existencias(
        User $actor,
        array $filtros
    ): Builder {
        $this->autorizar($actor);

        $consulta = Articulo::query()
            ->with('unidadMedida');

        if (! empty($filtros['tipo_articulo'])) {
            $consulta->where(
                'tipo',
                $filtros['tipo_articulo']
            );
        }

        if ($filtros['buscar'] !== '') {
            $buscar = $filtros['buscar'];

            $consulta->where(function (Builder $q) use ($buscar) {
                $q->where('nombre', 'like', '%'.$buscar.'%')
                    ->orWhere('codigo', 'like', '%'.$buscar.'%');
            });
        }

        switch ($filtros['disponibilidad'] ?? null) {
            case 'bajo':
                $consulta
                    ->where('stock_minimo', '>', 0)
                    ->whereRaw(
                        'stock_actual - stock_reservado <= stock_minimo'
                    );
                break;

            case 'agotado':
                $consulta->whereRaw(
                    'stock_actual - stock_reservado <= 0'
                );
                break;

            case 'con_stock':
                $consulta->whereRaw(
                    'stock_actual - stock_reservado > 0'
                );
                break;
        }

        return $consulta;
    }

    public function resumenExistencias(
        User $actor,
        Builder $consulta
    ): array {
        $this->autorizar($actor);

        $verCostos = $actor->can('costos.ver');

        $sql = '
            COUNT(*) AS articulos,

            COALESCE(SUM(
                CASE
                    WHEN stock_minimo > 0
                    AND stock_actual - stock_reservado <= stock_minimo
                    THEN 1 ELSE 0
                END
            ), 0) AS bajo_minimo,

            COALESCE(SUM(
                CASE
                    WHEN stock_actual - stock_reservado <= 0
                    THEN 1 ELSE 0
                END
            ), 0) AS sin_disponible
        ';

        if ($verCostos) {
            $sql .= ',
                COALESCE(SUM(
                    stock_actual * costo_promedio
                ), 0) AS valorizacion
            ';
        }

        $totales = (clone $consulta)
            ->toBase()
            ->selectRaw($sql)
            ->first();

        return [
            'articulos' => (int) $totales->articulos,
            'bajo_minimo' => (int) $totales->bajo_minimo,
            'sin_disponible' => (int) $totales->sin_disponible,
            'valorizacion' => $verCostos
                ? (string) $totales->valorizacion
                : null,
        ];
    }

    public function movimientos(
        User $actor,
        array $filtros
    ): Builder {
        $this->autorizar($actor);

        $consulta = MovimientoInventario::query()
            ->with([
                'articulo.unidadMedida',
                'usuario',
            ])
            ->whereBetween('registrado_at', [
                $filtros['inicio'],
                $filtros['fin'],
            ]);

        if (! empty($filtros['tipo_movimiento'])) {
            $consulta->where(
                'tipo',
                $filtros['tipo_movimiento']
            );
        }

        $consulta->whereHas('articulo', function (Builder $q) use ($filtros) {
            if (! empty($filtros['tipo_articulo'])) {
                $q->where('tipo', $filtros['tipo_articulo']);
            }

            if ($filtros['buscar'] !== '') {
                $buscar = $filtros['buscar'];

                $q->where(function (Builder $busqueda) use ($buscar) {
                    $busqueda
                        ->where('nombre', 'like', '%'.$buscar.'%')
                        ->orWhere('codigo', 'like', '%'.$buscar.'%');
                });
            }
        });

        return $consulta;
    }

    public function resumenMovimientos(
        User $actor,
        Builder $consulta
    ): array {
        $this->autorizar($actor);

        $totales = (clone $consulta)
            ->toBase()
            ->selectRaw('
                COUNT(*) AS movimientos,

                COUNT(DISTINCT articulo_id) AS articulos,

                COALESCE(SUM(
                    CASE WHEN cantidad > 0
                    THEN 1 ELSE 0 END
                ), 0) AS entradas,

                COALESCE(SUM(
                    CASE WHEN cantidad < 0
                    THEN 1 ELSE 0 END
                ), 0) AS salidas
            ')
            ->first();

        return [
            'movimientos' => (int) $totales->movimientos,
            'articulos' => (int) $totales->articulos,
            'entradas' => (int) $totales->entradas,
            'salidas' => (int) $totales->salidas,
        ];
    }
}
