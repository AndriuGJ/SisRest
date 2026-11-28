
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>{{ $titulo }}</title>

    <style>
        @page {
            margin: 28pt 30pt 42pt;
        }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            color: #27272a;
            margin: 0;
        }

        p, h1 {
            margin: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .encabezado {
            border-bottom: 1px solid #444;
            margin-bottom: 14px;
        }

        .encabezado td {
            vertical-align: middle;
            padding-bottom: 12px;
        }

        .logo {
            max-width: 70px;
            max-height: 65px;
        }

        .empresa {
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .titulo {
            font-size: 16px;
            font-weight: bold;
        }

        .secundario {
            color: #666;
            font-size: 8px;
            line-height: 1.6;
        }

        .derecha {
            text-align: right;
        }

        .filtros {
            margin-bottom: 14px;
            line-height: 1.8;
        }

        .resumen {
            margin-bottom: 16px;
            table-layout: fixed;
        }

        .resumen td {
            background: #f5f5f5;
            border: 1px solid #dedede;
            padding: 10px;
        }

        .resumen-valor {
            font-size: 14px;
            font-weight: bold;
            margin-top: 4px;
        }

        .detalle {
            table-layout: fixed;
            font-size: 8px;
        }

        .detalle thead {
            display: table-header-group;
        }

        .detalle th {
            background: #eee;
            border: 1px solid #d4d4d4;
            padding: 8px 5px;
            text-align: left;
        }

        .detalle td {
            border-bottom: 1px solid #dedede;
            padding: 7px 5px;
            vertical-align: top;
            word-wrap: break-word;
        }

        .detalle tr {
            page-break-inside: avoid;
        }

        .detalle tbody tr:nth-child(even) {
            background: #fafafa;
        }

        .numero {
            text-align: right;
        }

        .negativo {
            color: #b91c1c;
        }

        .positivo {
            color: #166534;
        }

        .pie {
            position: fixed;
            bottom: -24pt;
            left: 0;
            right: 0;
            border-top: 1px solid #dedede;
            padding-top: 6px;
            font-size: 8px;
            color: #666;
        }
    </style>
</head>

<body>

    @php
        $cantidad = fn ($valor) =>
            \App\Support\Cantidad::mostrar((string) $valor);

        $moneda = fn ($valor) =>
            'S/ '.number_format((float) $valor, 2, '.', ',');
    @endphp

    {{-- Encabezado --}}
    <table class="encabezado">
        <tr>
            @if ($logo)
                <td style="width: 80px;">
                    <img src="{{ $logo }}" class="logo" alt="Logo">
                </td>
            @endif

            <td>
                <p class="empresa">{{ $nombreEmpresa }}</p>

                @if ($empresa?->razon_social && $empresa->razon_social !== $nombreEmpresa)
                    <p class="secundario">
                        {{ $empresa->razon_social }}
                    </p>
                @endif

                @if ($empresa?->ruc)
                    <p class="secundario">
                        RUC: {{ $empresa->ruc }}
                    </p>
                @endif

                @if ($empresa?->direccion)
                    <p class="secundario">
                        {{ $empresa->direccion }}
                    </p>
                @endif
            </td>

            <td class="derecha" style="width: 45%;">
                <h1 class="titulo">{{ $titulo }}</h1>

                <p class="secundario">
                    Emitido: {{ $fechaEmision }}
                </p>

                <p class="secundario">
                    Generado por: {{ $usuario }}
                </p>
            </td>
        </tr>
    </table>

    {{-- Filtros --}}
    <table class="filtros">
        @if ($vista === 'kardex')
            <tr>
                <td colspan="2">
                    <strong>Período:</strong> {{ $periodo }}
                </td>
            </tr>
        @else
            <tr>
                <td colspan="2">
                    <strong>Existencias actuales al:</strong>
                    {{ $fechaEmision }}
                </td>
            </tr>
        @endif

        <tr>
            <td>
                <strong>Tipo de artículo:</strong>
                {{ $tipoArticuloNombre }}
            </td>

            <td>
                <strong>
                    {{ $vista === 'existencias' ? 'Disponibilidad:' : 'Movimiento:' }}
                </strong>
                {{ $filtroNombre }}
            </td>
        </tr>

        <tr>
            <td colspan="2">
                <strong>Búsqueda:</strong> {{ $buscar }}
            </td>
        </tr>
    </table>

    {{-- Resumen --}}
    <table class="resumen">
        <tr>
            @if ($vista === 'existencias')
                <td>
                    <p class="secundario">Artículos</p>
                    <p class="resumen-valor">
                        {{ $resumen['articulos'] }}
                    </p>
                </td>

                <td>
                    <p class="secundario">Bajo el mínimo</p>
                    <p class="resumen-valor">
                        {{ $resumen['bajo_minimo'] }}
                    </p>
                </td>

                <td>
                    <p class="secundario">Sin disponible</p>
                    <p class="resumen-valor">
                        {{ $resumen['sin_disponible'] }}
                    </p>
                </td>

                @if ($verCostos)
                    <td>
                        <p class="secundario">Valorización actual</p>
                        <p class="resumen-valor">
                            {{ $moneda($resumen['valorizacion']) }}
                        </p>
                    </td>
                @endif
            @else
                <td>
                    <p class="secundario">Movimientos</p>
                    <p class="resumen-valor">
                        {{ $resumen['movimientos'] }}
                    </p>
                </td>

                <td>
                    <p class="secundario">Entradas</p>
                    <p class="resumen-valor">
                        {{ $resumen['entradas'] }}
                    </p>
                </td>

                <td>
                    <p class="secundario">Salidas</p>
                    <p class="resumen-valor">
                        {{ $resumen['salidas'] }}
                    </p>
                </td>

                <td>
                    <p class="secundario">Artículos afectados</p>
                    <p class="resumen-valor">
                        {{ $resumen['articulos'] }}
                    </p>
                </td>
            @endif
        </tr>
    </table>

    {{-- Existencias --}}
    @if ($vista === 'existencias')

        <table class="detalle">
            <thead>
                <tr>
                    <th>Artículo</th>
                    <th>Unidad</th>
                    <th class="numero">Actual</th>
                    <th class="numero">Reservado</th>
                    <th class="numero">Disponible</th>
                    <th class="numero">Mínimo</th>
                    <th>Estado</th>

                    @if ($verCostos)
                        <th class="numero">Costo prom.</th>
                        <th class="numero">Valorización</th>
                    @endif
                </tr>
            </thead>

            <tbody>
                @forelse ($registros as $articulo)

                    @php
                        $agotado = bccomp(
                            $articulo->disponible, '0', 4
                        ) <= 0;

                        $bajo = bccomp(
                            $articulo->stock_minimo, '0', 4
                        ) > 0 && bccomp(
                            $articulo->disponible,
                            $articulo->stock_minimo,
                            4
                        ) <= 0;
                    @endphp

                    <tr>
                        <td>
                            <strong>{{ $articulo->nombre }}</strong>

                            <p class="secundario">
                                {{ $articulo->codigo }}
                            </p>

                            <p class="secundario">
                                {{ $tiposArticulo[$articulo->tipo->value] ?? 'Artículo' }}
                            </p>
                        </td>

                        <td>
                            {{ $articulo->unidadMedida->codigo }}
                        </td>

                        <td class="numero">
                            {{ $cantidad($articulo->stock_actual) }}
                        </td>

                        <td class="numero">
                            {{ $cantidad($articulo->stock_reservado) }}
                        </td>

                        <td class="numero {{ $agotado ? 'negativo' : '' }}">
                            {{ $cantidad($articulo->disponible) }}
                        </td>

                        <td class="numero">
                            {{ $cantidad($articulo->stock_minimo) }}
                        </td>

                        <td>
                            {{ $articulo->activo ? 'Activo' : 'Inactivo' }}

                            @if ($bajo)
                                <p class="secundario">
                                    Bajo el mínimo
                                </p>
                            @endif
                        </td>

                        @if ($verCostos)
                            <td class="numero">
                                {{ number_format(
                                    (float) $articulo->costo_promedio,
                                    6,
                                    '.',
                                    ','
                                ) }}
                            </td>

                            <td class="numero">
                                {{ $moneda(bcmul(
                                    $articulo->stock_actual,
                                    $articulo->costo_promedio,
                                    6
                                )) }}
                            </td>
                        @endif
                    </tr>

                @empty
                    <tr>
                        <td
                            colspan="{{ $verCostos ? 9 : 7 }}"
                            style="text-align: center; padding: 25px;"
                        >
                            No hay artículos con estos filtros.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

    @else

        {{-- Kardex --}}
        <table class="detalle">
            <thead>
                <tr>
                    <th>Fecha / Usuario</th>
                    <th>Artículo</th>
                    <th>Movimiento</th>
                    <th>Origen</th>
                    <th class="numero">Entrada</th>
                    <th class="numero">Salida</th>
                    <th class="numero">Anterior</th>
                    <th class="numero">Posterior</th>

                    @if ($verCostos)
                        <th class="numero">Costo unit.</th>
                    @endif
                </tr>
            </thead>

            <tbody>
                @forelse ($registros as $movimiento)

                    @php
                        $entrada = bccomp(
                            $movimiento->cantidad, '0', 4
                        ) > 0;

                        $magnitud = $entrada
                            ? $movimiento->cantidad
                            : bcsub(
                                '0', $movimiento->cantidad, 4
                            );

                        $origen = match ($movimiento->origen_type) {
                            \App\Models\Compra::class => 'Compra',
                            \App\Models\Preparacion::class => 'Preparación',
                            \App\Models\Pedido::class => 'Pedido',
                            default => 'Movimiento',
                        };
                    @endphp

                    <tr>
                        <td>
                            {{ $movimiento->registrado_at->format('d/m/Y H:i') }}

                            <p class="secundario">
                                {{ $movimiento->usuario->name }}
                            </p>
                        </td>

                        <td>
                            <strong>
                                {{ $movimiento->articulo->nombre }}
                            </strong>

                            <p class="secundario">
                                {{ $movimiento->articulo->codigo }}
                                ·
                                {{ $movimiento->articulo->unidadMedida->codigo }}
                            </p>
                        </td>

                        <td>
                            {{ $tiposMovimiento[$movimiento->tipo->value] ?? $movimiento->tipo->value }}

                            @if ($movimiento->motivo)
                                <p class="secundario">
                                    {{ $movimiento->motivo }}
                                </p>
                            @endif
                        </td>

                        <td>
                            {{ $origen }}

                            @if ($movimiento->origen_id)
                                <p class="secundario">
                                    #{{ $movimiento->origen_id }}
                                </p>
                            @endif
                        </td>

                        <td class="numero positivo">
                            {{ $entrada ? $cantidad($magnitud) : '—' }}
                        </td>

                        <td class="numero negativo">
                            {{ $entrada ? '—' : $cantidad($magnitud) }}
                        </td>

                        <td class="numero">
                            {{ $cantidad($movimiento->saldo_anterior) }}
                        </td>

                        <td class="numero">
                            <strong>
                                {{ $cantidad($movimiento->saldo_posterior) }}
                            </strong>
                        </td>

                        @if ($verCostos)
                            <td class="numero">
                                {{ number_format(
                                    (float) $movimiento->costo_unitario,
                                    6,
                                    '.',
                                    ','
                                ) }}
                            </td>
                        @endif
                    </tr>

                @empty
                    <tr>
                        <td
                            colspan="{{ $verCostos ? 9 : 8 }}"
                            style="text-align: center; padding: 25px;"
                        >
                            No hay movimientos con estos filtros.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

    @endif

    <p class="secundario" style="margin-top: 12px;">
        @if ($vista === 'existencias')
            Las existencias corresponden al momento de emisión.
            Disponible = stock actual menos stock reservado.
        @else
            Los saldos anterior y posterior corresponden
            a cada movimiento registrado.
            Las cantidades se expresan en la unidad del artículo.
        @endif
    </p>

    <div class="pie">
        SisRest | Reporte interno de inventario
    </div>

</body>
</html>
