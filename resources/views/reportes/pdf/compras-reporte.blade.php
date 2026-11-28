
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reporte de compras</title>

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
            font-weight: bold;
            font-size: 14px;
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

        .detalle tbody tr:nth-child(even) {
            background: #fafafa;
        }

        .detalle tr {
            page-break-inside: avoid;
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

        .advertencia {
            color: #a16207;
        }

        .pie {
            position: fixed;
            bottom: -24pt;
            left: 0;
            right: 0;
            border-top: 1px solid #dedede;
            padding-top: 6px;
            color: #666;
            font-size: 8px;
        }
    </style>
</head>

<body>

    @php
        $moneda = fn ($valor) => $valor === null
            ? '—'
            : 'S/ '.number_format((float) $valor, 2, '.', ',');

        $fecha = fn ($valor) => $valor
            ? \Carbon\CarbonImmutable::parse($valor)->format('d/m/Y')
            : '—';
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

            <td class="derecha" style="width: 44%;">
                <h1 class="titulo">
                    REPORTE DE COMPRAS
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
                <strong>Período de documentos:</strong>
                {{ $periodo }}
            </td>
        </tr>

        <tr>
            <td>
                <strong>Proveedor:</strong>
                {{ $proveedorNombre }}
            </td>

            <td>
                <strong>Estado:</strong>
                {{ $estadoNombre }}
            </td>
        </tr>

        <tr>
            <td>
                <strong>Situación de pago:</strong>
                {{ $pagoNombre }}
            </td>

            <td>
                <strong>Búsqueda:</strong>
                {{ $buscar }}
            </td>
        </tr>
    </table>

    {{-- Resumen --}}
    <table class="resumen">
        <tr>
            <td>
                <p class="secundario">
                    Compras confirmadas
                </p>

                <p class="resumen-valor">
                    {{ $resumen['confirmadas'] }}
                </p>

                <p class="secundario">
                    {{ $resumen['compras'] }} registros filtrados
                </p>
            </td>

            <td>
                <p class="secundario">
                    Total confirmado
                </p>

                <p class="resumen-valor">
                    {{ $moneda($resumen['total_confirmado']) }}
                </p>
            </td>

            <td>
                <p class="secundario">
                    Pagado actualmente
                </p>

                <p class="resumen-valor">
                    {{ $moneda($resumen['pagado']) }}
                </p>
            </td>

            <td>
                <p class="secundario">
                    Saldo pendiente actual
                </p>

                <p class="resumen-valor advertencia">
                    {{ $moneda($resumen['pendiente']) }}
                </p>

                <p class="secundario">
                    {{ $resumen['vencidas'] }} compras vencidas
                </p>
            </td>
        </tr>
    </table>

    {{-- Tabla --}}
    <table class="detalle">
        <thead>
            <tr>
                <th style="width: 23%;">
                    Compra / Proveedor
                </th>

                <th style="width: 17%;">
                    Fecha / Documento
                </th>

                <th style="width: 10%;">
                    Estado
                </th>

                <th style="width: 13%;">
                    Condición
                </th>

                <th class="numero" style="width: 12%;">
                    Total
                </th>

                <th class="numero" style="width: 12%;">
                    Pagado
                </th>

                <th class="numero" style="width: 13%;">
                    Pendiente / Pago
                </th>
            </tr>
        </thead>

        <tbody>
            @forelse ($compras as $compra)

                @php
                    $confirmada = $compra->estado === 'confirmada';

                    $saldo = $confirmada
                        ? (string) $compra->pendiente
                        : null;

                    $pagada = $confirmada
                        && bccomp($saldo, '0', 2) === 0;

                    $vencida = $confirmada
                        && $compra->condicion_pago === 'credito'
                        && $compra->fecha_vencimiento
                        && $compra->fecha_vencimiento < $hoy
                        && bccomp($saldo, '0', 2) > 0;

                    $situacion = !$confirmada
                        ? 'No aplica'
                        : ($pagada
                            ? 'Pagada'
                            : ($vencida ? 'Vencida' : 'Pendiente'));
                @endphp

                <tr>
                    <td>
                        <strong>{{ $compra->proveedor }}</strong>

                        <p class="secundario">
                            {{ $compra->codigo }}
                        </p>
                    </td>

                    <td>
                        {{ $fecha($compra->fecha) }}

                        @if ($compra->numero)
                            <p class="secundario">
                                {{ $compra->documento_nombre }}
                                {{ $compra->serie }}-{{ $compra->numero }}
                            </p>
                        @endif
                    </td>

                    <td>
                        {{ ucfirst($compra->estado) }}
                    </td>

                    <td>
                        {{ $compra->condicion_pago === 'credito' ? 'Crédito' : 'Contado' }}

                        @if ($compra->fecha_vencimiento)
                            <p class="secundario">
                                Vence:
                                {{ $fecha($compra->fecha_vencimiento) }}
                            </p>
                        @endif
                    </td>

                    <td class="numero">
                        {{ $moneda($compra->total) }}
                    </td>

                    <td class="numero">
                        {{ $confirmada
                            ? $moneda($compra->pagado)
                            : '—' }}
                    </td>

                    <td class="numero">
                        <strong>{{ $moneda($saldo) }}</strong>

                        <p class="{{ $vencida ? 'negativo' : ($pagada ? 'positivo' : 'advertencia') }}">
                            {{ $situacion }}
                        </p>
                    </td>
                </tr>

            @empty
                <tr>
                    <td colspan="7" style="text-align: center; padding: 25px;">
                        No hay compras con los filtros seleccionados.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p class="secundario" style="margin-top: 12px;">
        Los pagos y saldos corresponden a la fecha de emisión
        del reporte. Las compras en borrador y anuladas
        no forman parte de los totales financieros.
    </p>

    <div class="pie">
        SisRest | Reporte interno de compras
    </div>

</body>
</html>
