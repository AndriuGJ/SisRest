<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de comprobantes</title>

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

        h1, p {
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
            table-layout: fixed;
            margin-bottom: 16px;
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

        .positivo {
            color: #166534;
        }

        .negativo {
            color: #b91c1c;
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
        $moneda = fn ($valor) =>
            'S/ '.number_format((float) $valor, 2, '.', ',');

        $resueltos = $resumen['aceptados']
            + $resumen['observados'];
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

                @if ($empresa?->razon_social &&
                     $empresa->razon_social !== $nombreEmpresa)
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
                <h1 class="titulo">
                    REPORTE DE COMPROBANTES
                </h1>

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
        <tr>
            <td colspan="2">
                <strong>Período:</strong> {{ $periodo }}
            </td>
        </tr>

        <tr>
            <td>
                <strong>Tipo:</strong> {{ $tipoNombre }}
            </td>
            <td>
                <strong>Entorno:</strong> {{ $entornoNombre }}
            </td>
        </tr>

        <tr>
            <td>
                <strong>Estado:</strong> {{ $estadoNombre }}
            </td>
            <td>
                <strong>SUNAT:</strong> {{ $sunatNombre }}
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
            <td>
                <p class="secundario">Documentos</p>
                <p class="resumen-valor">
                    {{ $resumen['documentos'] }}
                </p>
                <p class="secundario">
                    {{ $resumen['pendientes'] }} por resolver
                </p>
            </td>

            <td>
                <p class="secundario">
                    Aceptados / observados
                </p>
                <p class="resumen-valor positivo">
                    {{ $resueltos }}
                </p>
                <p class="secundario">
                    {{ $resumen['rechazados'] }} rechazados
                </p>
            </td>

            <td>
                <p class="secundario">
                    Importe emitido Producción
                </p>
                <p class="resumen-valor">
                    {{ $moneda($resumen['importe_produccion']) }}
                </p>
            </td>

            <td>
                <p class="secundario">
                    Importe emitido Beta
                </p>
                <p class="resumen-valor advertencia">
                    {{ $moneda($resumen['importe_beta']) }}
                </p>
                <p class="secundario">Pruebas</p>
            </td>
        </tr>
    </table>

    {{-- Detalle --}}
    <table class="detalle">
        <thead>
            <tr>
                <th style="width: 18%;">Comprobante</th>
                <th style="width: 12%;">Fecha</th>
                <th style="width: 23%;">Cliente</th>
                <th style="width: 12%;">Entorno</th>
                <th style="width: 12%;">Estado</th>
                <th style="width: 12%;">SUNAT</th>
                <th class="numero" style="width: 11%;">Total</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($comprobantes as $comprobante)
                @php
                    $tipo = $tipos[
                        $comprobante->serie?->tipoComprobante?->codigo
                    ] ?? 'Comprobante';

                    $numero = \App\Services\Facturacion\XmlFiscal::numero(
                        $comprobante
                    );

                    $sunat = $comprobante->estado_sunat->value;

                    $colorSunat = match ($sunat) {
                        'aceptado', 'observado' => 'positivo',
                        'rechazado' => 'negativo',
                        default => 'advertencia',
                    };
                @endphp

                <tr>
                    <td>
                        <strong>{{ $numero }}</strong>
                        <p class="secundario">{{ $tipo }}</p>
                    </td>

                    <td>
                        {{ $comprobante->fecha_emision->format('d/m/Y') }}
                    </td>

                    <td>
                        <strong>
                            {{ $comprobante->receptor['nombre'] ?? 'Sin nombre' }}
                        </strong>

                        <p class="secundario">
                            {{ $comprobante->receptor['numero'] ?? '—' }}
                        </p>
                    </td>

                    <td class="{{ $comprobante->entorno === 'beta' ? 'advertencia' : '' }}">
                        {{ $comprobante->entorno === 'beta' ? 'Beta' : 'Producción' }}
                    </td>

                    <td>
                        {{ ucfirst($comprobante->estado->value) }}
                    </td>

                    <td class="{{ $colorSunat }}">
                        {{ $estadosSunat[$sunat] ?? ucfirst($sunat) }}
                    </td>

                    <td class="numero">
                        {{ $moneda($comprobante->total) }}
                    </td>
                </tr>

            @empty
                <tr>
                    <td colspan="7"
                        style="text-align: center; padding: 25px;">
                        No hay comprobantes con estos filtros.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p class="secundario" style="margin-top: 12px;">
        Los importes de Producción y Beta se muestran por separado.
        Los documentos Beta son de prueba.
        El estado SUNAT es independiente del estado documental
        y del pago de la venta.
    </p>

    <div class="pie">
        SisRest | Reporte interno de comprobantes
    </div>
</body>
</html>
