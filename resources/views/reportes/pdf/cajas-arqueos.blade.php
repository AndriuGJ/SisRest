<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de cajas y arqueos</title>

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
            margin-top: 4px;
            font-size: 14px;
            font-weight: bold;
        }

        .detalle {
            table-layout: fixed;
            font-size: 8px;
        }

        .detalle thead {
            display: table-header-group;
        }

        .detalle th {
            background: #eeeeee;
            border: 1px solid #d4d4d4;
            padding: 7px 4px;
            text-align: left;
        }

        .detalle td {
            padding: 7px 4px;
            border-bottom: 1px solid #dedede;
            vertical-align: top;
            word-wrap: break-word;
        }

        .detalle .derecha {
            text-align: right;
        }

        .detalle tr {
            page-break-inside: avoid;
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
        $moneda = fn ($valor) => $valor === null
            ? '—'
            : 'S/ '.number_format((float) $valor, 2, '.', ',');

        $fecha = fn ($valor) => $valor
            ? \Carbon\CarbonImmutable::parse($valor)->format('d/m/Y H:i')
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
                    REPORTE DE CAJAS Y ARQUEOS
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
                <strong>Período de apertura:</strong> {{ $periodo }}
            </td>
        </tr>

        <tr>
            <td>
                <strong>Caja:</strong> {{ $cajaNombre }}
            </td>

            <td>
                <strong>Estado:</strong> {{ $estadoNombre }}
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
                <p class="secundario">Turnos</p>
                <p class="resumen-valor">{{ $resumen['turnos'] }}</p>

                <p class="secundario">
                    {{ $resumen['abiertas'] }} abiertos /
                    {{ $resumen['cerradas'] }} cerrados
                </p>
            </td>

            <td>
                <p class="secundario">Cobros netos</p>
                <p class="resumen-valor">
                    {{ $moneda($resumen['neto_cobrado']) }}
                </p>
            </td>

            <td>
                <p class="secundario">Faltantes al cierre</p>
                <p class="resumen-valor negativo">
                    {{ $moneda($resumen['faltantes']) }}
                </p>
            </td>

            <td>
                <p class="secundario">Sobrantes al cierre</p>
                <p class="resumen-valor positivo">
                    {{ $moneda($resumen['sobrantes']) }}
                </p>
            </td>
        </tr>
    </table>

    {{-- Detalle --}}
    <table class="detalle">

        <thead>
            <tr>
                <th style="width: 15%;">Caja / Responsable</th>
                <th style="width: 13%;">Apertura / Cierre</th>
                <th style="width: 8%;">Estado</th>
                <th class="derecha" style="width: 9%;">Fondo</th>
                <th class="derecha" style="width: 13%;">Movimientos</th>
                <th class="derecha" style="width: 12%;">Cobros netos</th>
                <th class="derecha" style="width: 10%;">Esperado</th>
                <th class="derecha" style="width: 10%;">Contado</th>
                <th class="derecha" style="width: 10%;">Diferencia</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($turnos as $turno)

                @php
                    $cerrada = $turno->estado === 'cerrada';

                    $diferencia = $cerrada
                        ? $turno->diferencia
                        : null;

                    $color = match (true) {
                        $diferencia === null => '',
                        bccomp((string) $diferencia, '0', 2) < 0 => 'negativo',
                        default => 'positivo',
                    };
                @endphp

                <tr>
                    <td>
                        <strong>{{ $turno->caja }}</strong>

                        <p class="secundario">
                            {{ $turno->responsable }}
                        </p>

                        <p class="secundario">
                            Turno #{{ $turno->id }}
                        </p>
                    </td>

                    <td>
                        <p>{{ $fecha($turno->abierta_at) }}</p>

                        <p class="secundario">
                            {{ $fecha($turno->cerrada_at) }}
                        </p>
                    </td>

                    <td>
                        {{ $cerrada ? 'Cerrada' : 'Abierta' }}

                        <p class="secundario">
                            Parciales: {{ $turno->arqueos_parciales }}
                        </p>
                    </td>

                    <td class="derecha">
                        {{ $moneda($turno->fondo_inicial) }}
                    </td>

                    <td class="derecha">
                        <p class="positivo">
                            + {{ $moneda($turno->ingresos ?? 0) }}
                        </p>

                        <p class="negativo">
                            − {{ $moneda($turno->egresos ?? 0) }}
                        </p>

                        <p class="secundario">
                            R: {{ $moneda($turno->retiros ?? 0) }}
                        </p>
                    </td>

                    <td class="derecha">
                        <strong>
                            {{ $moneda($turno->neto_cobrado) }}
                        </strong>

                        <p class="secundario">
                            C: {{ $moneda($turno->cobrado) }}
                        </p>

                        <p class="secundario">
                            D: {{ $moneda($turno->devuelto) }}
                        </p>
                    </td>

                    <td class="derecha">
                        {{ $moneda($turno->esperado) }}
                    </td>

                    <td class="derecha">
                        {{ $moneda($cerrada ? $turno->contado : null) }}
                    </td>

                    <td class="derecha {{ $color }}">
                        <strong>{{ $moneda($diferencia) }}</strong>

                        @if ($diferencia !== null)
                            <p>
                                {{ bccomp((string) $diferencia, '0', 2) < 0
                                    ? 'Faltante'
                                    : (bccomp((string) $diferencia, '0', 2) > 0
                                        ? 'Sobrante'
                                        : 'Sin diferencia') }}
                            </p>
                        @endif
                    </td>
                </tr>

                @if ($cerrada && $turno->observaciones_cierre)
                    <tr>
                        <td colspan="9" class="secundario">
                            <strong>Observaciones:</strong>
                            {{ $turno->observaciones_cierre }}
                        </td>
                    </tr>
                @endif

            @empty

                <tr>
                    <td colspan="9" style="text-align: center; padding: 25px;">
                        No hay turnos con los filtros seleccionados.
                    </td>
                </tr>

            @endforelse
        </tbody>

    </table>

    <p class="secundario" style="margin-top: 12px;">
        R: retiros. C: cobrado. D: devuelto.
        Los movimientos corresponden al efectivo físico.
        Las diferencias corresponden al arqueo final.
        Los turnos se muestran completos según su fecha de apertura.
    </p>

    <div class="pie">
        SisRest | Reporte interno de cajas y arqueos
    </div>

</body>
</html>
