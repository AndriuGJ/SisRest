
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reporte de productos vendidos</title>

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

        h1, p {
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
            padding: 8px 5px;
            text-align: left;
        }

        .detalle td {
            padding: 7px 5px;
            border-bottom: 1px solid #dedede;
            vertical-align: middle;
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
        $moneda = fn ($valor) =>
            'S/ '.number_format((float) $valor, 2, '.', ',');

        $cantidad = fn ($valor) =>
            rtrim(rtrim(number_format((float) $valor, 4, '.', ','), '0'), '.');
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
                    <p class="secundario">{{ $empresa->razon_social }}</p>
                @endif

                @if ($empresa?->ruc)
                    <p class="secundario">RUC: {{ $empresa->ruc }}</p>
                @endif

                @if ($empresa?->direccion)
                    <p class="secundario">{{ $empresa->direccion }}</p>
                @endif
            </td>

            <td class="derecha" style="width: 46%;">
                <h1 class="titulo">
                    REPORTE DE PRODUCTOS VENDIDOS
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

    {{-- Filtros --}}
    <table class="filtros">
        <tr>
            <td colspan="2">
                <strong>Período:</strong> {{ $periodo }}
            </td>
        </tr>

        <tr>
            <td>
                <strong>Categoría:</strong> {{ $categoriaNombre }}
            </td>
            <td>
                <strong>Caja:</strong> {{ $cajaNombre }}
            </td>
        </tr>

        <tr>
            <td>
                <strong>Modalidad:</strong> {{ $modalidadNombre }}
            </td>
            <td>
                <strong>Búsqueda:</strong> {{ $buscar }}
            </td>
        </tr>
    </table>

    {{-- Resumen --}}
    <table class="resumen">
        <tr>
            <td>
                <p class="resumen-label">Productos</p>
                <p class="resumen-valor">
                    {{ $resumen['productos'] }}
                </p>
            </td>

            <td>
                <p class="resumen-label">Cantidad neta</p>
                <p class="resumen-valor">
                    {{ $cantidad($resumen['cantidad_neta']) }}
                </p>
            </td>

            <td>
                <p class="resumen-label">Descuentos aplicados</p>
                <p class="resumen-valor">
                    {{ $moneda($resumen['descuento']) }}
                </p>
            </td>

            <td>
                <p class="resumen-label">Importe neto</p>
                <p class="resumen-valor {{ bccomp($resumen['importe_neto'], '0', 2) < 0 ? 'negativo' : 'positivo' }}">
                    {{ $moneda($resumen['importe_neto']) }}
                </p>
            </td>
        </tr>
    </table>

    {{-- Detalle --}}
    <table class="detalle">
        <thead>
            <tr>
                <th style="width: 24%;">Producto</th>
                <th class="derecha" style="width: 10%;">Cobrados</th>
                <th class="derecha" style="width: 10%;">Devueltos</th>
                <th class="derecha" style="width: 10%;">Cantidad neta</th>
                <th class="derecha" style="width: 10%;">Descuento</th>
                <th class="derecha" style="width: 12%;">Importe cobrado</th>
                <th class="derecha" style="width: 12%;">Importe devuelto</th>
                <th class="derecha" style="width: 12%;">Importe neto</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($productos as $producto)
                <tr>
                    <td>
                        <strong>{{ $producto->nombre }}</strong>
                        <p class="secundario">{{ $producto->codigo }}</p>
                        <p class="secundario">
                            {{ $producto->categoria ?? 'Sin categoría' }}
                        </p>
                    </td>

                    <td class="derecha">
                        {{ $cantidad($producto->cantidad_cobrada) }}
                    </td>

                    <td class="derecha {{ bccomp((string) $producto->cantidad_devuelta, '0', 4) > 0 ? 'negativo' : '' }}">
                        {{ $cantidad($producto->cantidad_devuelta) }}
                    </td>

                    <td class="derecha">
                        <strong>{{ $cantidad($producto->cantidad_neta) }}</strong>
                    </td>

                    <td class="derecha">
                        {{ $moneda($producto->descuento) }}
                    </td>

                    <td class="derecha">
                        {{ $moneda($producto->importe_cobrado) }}
                    </td>

                    <td class="derecha {{ bccomp((string) $producto->importe_devuelto, '0', 2) > 0 ? 'negativo' : '' }}">
                        {{ $moneda($producto->importe_devuelto) }}
                    </td>

                    <td class="derecha {{ bccomp((string) $producto->importe_neto, '0', 2) < 0 ? 'negativo' : '' }}">
                        <strong>{{ $moneda($producto->importe_neto) }}</strong>
                    </td>
                </tr>

            @empty
                <tr>
                    <td colspan="8" style="text-align: center; padding: 25px;">
                        No hay productos con los filtros seleccionados.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Total --}}
    <table style="margin-top: 12px; page-break-inside: avoid;">
        <tr>
            <td class="derecha" style="padding: 10px; background: #eeeeee;">
                <strong>
                    TOTAL NETO: {{ $moneda($resumen['importe_neto']) }}
                </strong>
            </td>
        </tr>
    </table>

    <p class="secundario" style="margin-top: 8px;">
        Los importes no incluyen cargos de envío.
        Los descuentos corresponden a los cobros del período.
    </p>

    <div class="pie">
        SisRest | Reporte interno de productos vendidos
    </div>

</body>
</html>
