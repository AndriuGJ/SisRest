<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reporte de ventas y cobros</title>

    <style>
        @page {
            margin: 28pt 30pt 42pt;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            color: #27272a;
            margin: 0;
        }

        h1,
        h2,
        p {
            margin: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .encabezado {
            margin-bottom: 14px;
            border-bottom: 1px solid #444;
        }

        .encabezado td {
            padding-bottom: 12px;
            vertical-align: middle;
        }

        .logo {
            max-width: 70px;
            max-height: 65px;
        }

        .empresa {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .titulo {
            font-size: 16px;
            font-weight: bold;
        }

        .secundario {
            color: #666;
            font-size: 9px;
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

        .resumen-label {
            color: #666;
            font-size: 9px;
            margin-bottom: 4px;
        }

        .resumen-valor {
            font-size: 14px;
            font-weight: bold;
        }

        .detalle {
            table-layout: fixed;
        }

        .detalle thead {
            display: table-header-group;
        }

        .detalle th {
            background: #eeeeee;
            border: 1px solid #d4d4d4;
            padding: 8px 6px;
            font-size: 9px;
            text-align: left;
        }

        .detalle td {
            border-bottom: 1px solid #dedede;
            padding: 7px 6px;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .detalle tr {
            page-break-inside: avoid;
        }

        .detalle .derecha {
            text-align: right;
        }

        .detalle tbody tr:nth-child(even) {
            background: #fafafa;
        }

        .negativo {
            color: #b91c1c;
        }

        .positivo {
            color: #166534;
        }

        .sin-resultados {
            text-align: center;
            padding: 25px !important;
            color: #666;
        }

        .pie {
            position: fixed;
            bottom: -24pt;
            left: 0;
            right: 0;
            border-top: 1px solid #d4d4d4;
            padding-top: 6px;
            font-size: 8px;
            color: #666;
        }
    </style>
</head>

<body>

    @php
        $moneda = fn($valor) => 'S/ ' . number_format((float) $valor, 2, '.', ',');

        $tipos = [
            'cobro' => 'Cobros',
            'devolucion' => 'Devoluciones',
        ];
    @endphp

    {{-- Encabezado de empresa --}}
    <table class="encabezado">
        <tr>

            @if ($logo)
                <td style="width: 80px;">
                    <img src="{{ $logo }}" class="logo" alt="Logo">
                </td>
            @endif

            <td>
                <p class="empresa">
                    {{ $nombreEmpresa }}
                </p>

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

            <td class="derecha" style="width: 44%;">
                <h1 class="titulo">
                    REPORTE DE VENTAS Y COBROS
                </h1>

                <p class="secundario">
                    Fecha de emisión: {{ $fechaEmision }}
                </p>

                <p class="secundario">
                    Generado por: {{ $usuario }}
                </p>
            </td>

        </tr>
    </table>

    {{-- Filtros aplicados --}}
    <table class="filtros">

        <tr>
            <td colspan="2">
                <strong>Período:</strong>

                {{ $filtros['inicio']->format('d/m/Y') }}

                al

                {{ $filtros['fin']->format('d/m/Y') }}
            </td>
        </tr>

        <tr>
            <td>
                <strong>Caja:</strong>
                {{ $cajaNombre }}
            </td>

            <td>
                <strong>Modalidad:</strong>

                {{ $modalidades[$filtros['modalidad'] ?? ''] ?? 'Todas' }}
            </td>
        </tr>

        <tr>
            <td>
                <strong>Operación:</strong>

                {{ $tipos[$filtros['tipo'] ?? ''] ?? 'Todas' }}
            </td>

            <td>
                <strong>Búsqueda:</strong>

                {{ $filtros['buscar'] !== '' ? $filtros['buscar'] : 'Sin filtro' }}
            </td>
        </tr>

    </table>

    {{-- Resumen --}}
    <table class="resumen">
        <tr>

            <td>
                <p class="resumen-label">Total cobrado</p>

                <p class="resumen-valor">
                    {{ $moneda($resumen['cobrado']) }}
                </p>
            </td>

            <td>
                <p class="resumen-label">Devoluciones</p>

                <p class="resumen-valor negativo">
                    {{ $moneda($resumen['devuelto']) }}
                </p>
            </td>

            <td>
                <p class="resumen-label">Cobros netos</p>

                <p class="resumen-valor {{ bccomp($resumen['neto'], '0', 2) < 0 ? 'negativo' : 'positivo' }}">
                    {{ $moneda($resumen['neto']) }}
                </p>
            </td>

            <td>
                <p class="resumen-label">Operaciones</p>

                <p class="resumen-valor">
                    {{ $resumen['operaciones'] }}
                </p>
            </td>

        </tr>
    </table>

    {{-- Detalle de operaciones --}}
    <table class="detalle">

        <thead>
            <tr>
                <th style="width: 4%;">N°</th>
                <th style="width: 21%;">Código / Fecha</th>
                <th style="width: 22%;">Cliente / Pedido</th>
                <th style="width: 13%;">Operación</th>
                <th style="width: 13%;">Modalidad</th>
                <th style="width: 12%;">Caja</th>
                <th class="derecha" style="width: 15%;">Importe</th>
            </tr>
        </thead>

        <tbody>

            @forelse ($operaciones as $operacion)
                <tr>
                    <td>
                        {{ $loop->iteration }}
                    </td>

                    <td>
                        <strong>{{ $operacion->codigo }}</strong>

                        <p class="secundario">
                            {{ \Carbon\CarbonImmutable::parse($operacion->fecha)->format('d/m/Y H:i') }}
                        </p>

                        @if ($operacion->tipo === 'devolucion')
                            <p class="secundario">
                                Cobro: {{ $operacion->codigo_cobro }}
                            </p>
                        @endif
                    </td>

                    <td>
                        {{ $operacion->cliente ?: 'Sin cliente' }}

                        <p class="secundario">
                            {{ $operacion->pedido }}
                        </p>
                    </td>

                    <td>
                        @if ($operacion->tipo === 'cobro')
                            <span class="positivo">Cobro</span>
                        @else
                            <span class="negativo">Devolución</span>
                        @endif
                    </td>

                    <td>
                        {{ $modalidades[$operacion->modalidad] ?? 'Otra' }}
                    </td>

                    <td>
                        {{ $operacion->caja }}
                    </td>

                    <td class="derecha {{ $operacion->tipo === 'devolucion' ? 'negativo' : '' }}">
                        {{ $moneda($operacion->importe) }}
                    </td>
                </tr>

            @empty

                <tr>
                    <td colspan="7" class="sin-resultados">
                        No hay operaciones con los filtros seleccionados.
                    </td>
                </tr>
            @endforelse

        </tbody>

    </table>

    {{-- Total final --}}
    <table style="margin-top: 12px; page-break-inside: avoid;">
        <tr>
            <td class="derecha" style="padding: 10px; background: #eeeeee;">
                <strong>
                    TOTAL NETO: {{ $moneda($resumen['neto']) }}
                </strong>
            </td>
        </tr>
    </table>

    {{-- Pie de página --}}
    <div class="pie">
        SisRest | Reporte interno de ventas y cobros
    </div>

</body>

</html>
