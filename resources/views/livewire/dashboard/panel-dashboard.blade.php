<div class="min-w-0 space-y-4 text-zinc-800 dark:text-zinc-100">
    {{-- Encabezado: actualizar conserva el intervalo elegido. --}}
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Dashboard</h1>
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Resumen de las operaciones del restaurante</p>
        </div>
        <a href="{{ route('dashboard', $verCobros ? ($personalizado ? ['desde' => $desde, 'hasta' => $hasta] : ['periodo' => $periodo]) : []) }}"
            class="inline-flex h-9 items-center gap-2 rounded border border-zinc-300 bg-white px-3 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">
            <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8 8 0 1 0-2.3 6M20 4v7h-7"/></svg>
            Actualizar
        </a>
    </header>

    {{-- Resumen operativo: no depende del rango de fechas. --}}
    @php
        $moneda = fn ($importe) => 'S/ '.number_format((float) $importe, 2, '.', ',');
        $cantidad = fn ($valor) => rtrim(rtrim(number_format((float) $valor, 4, '.', ''), '0'), '.');
        $indicadores = [];

        if ($verCobros) {
            $indicadores[] = ['titulo' => 'Cobros netos de hoy', 'valor' => $moneda($cobrosNetosHoy), 'detalle' => 'Cobros menos devoluciones', 'icono' => 'dinero', 'color' => 'bg-sky-50 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300'];
            $indicadores[] = ['titulo' => 'Cobros realizados hoy', 'valor' => $cantidadCobros, 'detalle' => 'Operaciones confirmadas', 'icono' => 'recibo', 'color' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'];
        }
        if ($verPedidos) {
            $indicadores[] = ['titulo' => 'Pedidos activos', 'valor' => $pedidosActivos, 'detalle' => 'Atenciones sin finalizar', 'icono' => 'pedidos', 'color' => 'bg-violet-50 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300'];
        }
        if ($verCajas) {
            $indicadores[] = ['titulo' => 'Cajas abiertas', 'valor' => $cajasAbiertas, 'detalle' => 'Turnos actualmente abiertos', 'icono' => 'caja', 'color' => 'bg-teal-50 text-teal-700 dark:bg-teal-950/50 dark:text-teal-300'];
        }
        if ($verInventario) {
            $indicadores[] = ['titulo' => 'Existencias bajas', 'valor' => $stockBajo, 'detalle' => 'Artículos que requieren atención', 'icono' => 'alerta', 'color' => $stockBajo > 0 ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'];
        }
    @endphp

    @if (count($indicadores) > 0)
        <section aria-labelledby="dashboard-resumen">
            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="dashboard-resumen" class="text-sm font-semibold">Resumen operativo</h2>
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Hoy · {{ now()->format('d/m/Y') }}</span>
            </div>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,165px),1fr))] gap-2.5">
                @foreach ($indicadores as $indicador)
                    <article class="min-w-0 rounded border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex items-start justify-between gap-2">
                            <p class="min-w-0 text-xs font-medium leading-5 text-zinc-600 dark:text-zinc-300">{{ $indicador['titulo'] }}</p>
                            <span class="grid size-8 shrink-0 place-items-center rounded {{ $indicador['color'] }}">
                                <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    @switch($indicador['icono'])
                                        @case('dinero')
                                            <rect x="3" y="6" width="18" height="12" rx="1.5"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.1M18 12h.1"/>
                                            @break
                                        @case('recibo')
                                            <path d="M5 3l3 2 4-2 4 2 3-2v18l-3-2-4 2-4-2-3 2zM9 9h6M9 13h6"/>
                                            @break
                                        @case('pedidos')
                                            <path d="M3 5v5a3 3 0 0 0 6 0V5M6 5v16M18 3c-3 3-4 6-4 10h4M18 3v18"/>
                                            @break
                                        @case('caja')
                                            <rect x="3" y="11" width="18" height="10" rx="1"/><path d="M6 11V4h10v7M7 16h10M10 18h4"/>
                                            @break
                                        @default
                                            <path d="M12 3 2 20h20L12 3zM12 9v4M12 16h.01"/>
                                    @endswitch
                                </svg>
                            </span>
                        </div>
                        <p class="mt-1 break-words text-[1.375rem] font-semibold leading-tight tabular-nums">{{ $indicador['valor'] }}</p>
                        <p class="mt-1 text-[11px] leading-4 text-zinc-500 dark:text-zinc-400">{{ $indicador['detalle'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if ($verCobros)
        {{-- Análisis financiero: todos estos bloques respetan verCobros. --}}
        <section aria-labelledby="dashboard-analisis" class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="dashboard-analisis" class="text-sm font-semibold">Análisis de cobros</h2>
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Período: {{ $personalizado ? $desde.' — '.$hasta : 'Últimos '.$periodo.' días' }}</span>
            </div>

            {{-- Una sola barra de filtros: 7/30 días y fechas personalizadas. --}}
            <div class="flex flex-wrap items-end justify-between gap-3 rounded border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="min-w-0">
                    <p class="mb-1 text-xs font-medium text-zinc-600 dark:text-zinc-300">Período</p>
                    <div class="inline-flex flex-wrap overflow-hidden rounded border border-zinc-200 dark:border-zinc-700">
                        @foreach ([7, 30] as $dias)
                            <a href="{{ route('dashboard', ['periodo' => $dias]) }}" wire:navigate
                                @if (!$personalizado && $periodo === $dias) aria-current="true" @endif
                                class="inline-flex h-9 items-center px-3 text-xs font-medium {{ !$loop->first ? 'border-l border-zinc-200 dark:border-zinc-700' : '' }} {{ !$personalizado && $periodo === $dias ? 'bg-zinc-800 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-white text-zinc-600 hover:bg-zinc-50 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
                                {{ $dias }} días
                            </a>
                        @endforeach
                    </div>
                </div>
                <form action="{{ route('dashboard') }}" method="GET" class="flex min-w-0 flex-wrap items-end gap-2">
                    <label class="grid min-w-0 gap-1 text-xs font-medium text-zinc-600 dark:text-zinc-300">
                        Desde
                        <input type="date" name="desde" value="{{ $desde }}" max="{{ now()->toDateString() }}" required
                            class="h-9 max-w-full rounded border border-zinc-300 bg-white px-2 text-sm font-normal text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                    </label>
                    <label class="grid min-w-0 gap-1 text-xs font-medium text-zinc-600 dark:text-zinc-300">
                        Hasta
                        <input type="date" name="hasta" value="{{ $hasta }}" max="{{ now()->toDateString() }}" required
                            class="h-9 max-w-full rounded border border-zinc-300 bg-white px-2 text-sm font-normal text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                    </label>
                    <button type="submit" class="h-9 rounded bg-[#2478A0] px-3 text-xs font-medium text-white hover:bg-[#1e6485]">Aplicar fechas</button>
                    <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex h-9 items-center rounded px-2 text-xs font-medium text-zinc-500 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">Restablecer</a>
                </form>
                @if ($errorFechas)
                    <p role="alert" class="w-full border-t border-zinc-200 pt-2 text-xs text-red-700 dark:border-zinc-700 dark:text-red-400">{{ $errorFechas }}</p>
                @endif
            </div>

            {{-- Tendencia principal y comparación, alineadas en escritorio. --}}
            <div class="grid min-w-0 grid-cols-1 gap-3 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <section class="min-w-0 rounded border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3">
                        <h3 class="text-sm font-semibold">Cobros netos {{ $etiquetaPeriodo }}</h3>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Cobros confirmados menos devoluciones confirmadas</p>
                    </div>
                    <div wire:ignore class="relative h-56 w-full sm:h-64">
                        <canvas id="dashboard-grafico-ventas" data-dashboard-chart="ventas"
                            data-serie='@json($grafico)' role="img" aria-label="Cobros netos {{ $etiquetaPeriodo }}"></canvas>
                    </div>
                </section>

                <section class="flex min-w-0 flex-col rounded border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3">
                        <h3 class="text-sm font-semibold">Comparación de cobros netos</h3>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Períodos de igual duración</p>
                    </div>
                    @php
                        $direccion = bccomp((string) $comparacion['diferencia'], '0', 2);
                    @endphp
                    <dl class="grid flex-1 gap-3 sm:grid-cols-3 xl:grid-cols-1 xl:gap-0 xl:divide-y xl:divide-zinc-200 xl:dark:divide-zinc-700">
                        <div class="min-w-0 py-2 xl:pb-3">
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Período seleccionado</dt>
                            <dd class="mt-1 break-words text-xl font-semibold tabular-nums text-[#2478A0] dark:text-sky-300">{{ $moneda($comparacion['actual']) }}</dd>
                        </div>
                        <div class="min-w-0 py-2 xl:py-3">
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Período anterior</dt>
                            <dd class="mt-1 break-words text-xl font-semibold tabular-nums">{{ $moneda($comparacion['anterior']) }}</dd>
                            <dd class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ $periodoAnterior }}</dd>
                        </div>
                        <div class="min-w-0 py-2 xl:pt-3">
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Diferencia</dt>
                            <dd class="mt-1 break-words text-xl font-semibold tabular-nums {{ $direccion > 0 ? 'text-emerald-700 dark:text-emerald-400' : ($direccion < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-600 dark:text-zinc-300') }}">{{ $direccion > 0 ? '+' : '' }}{{ $moneda($comparacion['diferencia']) }}</dd>
                            <dd class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ $direccion > 0 ? 'Aumento respecto al anterior' : ($direccion < 0 ? 'Disminución respecto al anterior' : 'Sin variación') }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            {{-- Los cuatro gráficos auxiliares reutilizan los selectores existentes de Chart.js. --}}
            @php
                $panelesGraficos = [
                    ['tipo' => 'cajas', 'titulo' => 'Cobros netos por caja', 'detalle' => 'Cobros menos devoluciones', 'serie' => $cobrosPorCaja, 'vacio' => 'Sin operaciones registradas en este período.'],
                    ['tipo' => 'productos', 'titulo' => 'Productos más cobrados', 'detalle' => 'Top 5 por unidades · Sin descontar devoluciones', 'serie' => $productosMasCobrados, 'vacio' => 'Sin productos cobrados en este período.'],
                    ['tipo' => 'medios', 'titulo' => 'Pagos por medio', 'detalle' => 'Importes recibidos · Sin descontar devoluciones', 'serie' => $mediosPago, 'vacio' => 'Sin pagos registrados en este período.'],
                    ['tipo' => 'modalidades', 'titulo' => 'Cobros por modalidad de atención', 'detalle' => 'Importes cobrados · Sin descontar devoluciones', 'serie' => $cobrosPorModalidad, 'vacio' => 'Sin importes cobrados en este período.'],
                ];
            @endphp
            <div class="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-4">
                @foreach ($panelesGraficos as $panel)
                    <section class="flex min-w-0 flex-col rounded border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900" wire:key="dashboard-panel-{{ $panel['tipo'] }}">
                        <div class="mb-3 flex-1">
                            <h3 class="text-sm font-semibold">{{ $panel['titulo'] }}</h3>
                            <p class="mt-1 text-[11px] leading-4 text-zinc-500 dark:text-zinc-400">{{ $panel['detalle'] }}</p>
                        </div>
                        @if (count($panel['serie']['etiquetas']) > 0)
                            <div wire:ignore class="relative h-52 w-full">
                                <canvas data-dashboard-chart="{{ $panel['tipo'] }}" data-serie="{{ json_encode($panel['serie'], JSON_THROW_ON_ERROR) }}" role="img" aria-label="{{ $panel['titulo'] }}"></canvas>
                            </div>
                        @else
                            <div class="flex h-52 items-center justify-center px-3 text-center text-xs text-zinc-500 dark:text-zinc-400">{{ $panel['vacio'] }}</div>
                        @endif
                    </section>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Operaciones e inventario: permisos independientes, sin duplicar información. --}}
    @if ($verCobros || $verInventario)
        <section aria-labelledby="dashboard-seguimiento" class="space-y-2">
            <h2 id="dashboard-seguimiento" class="text-sm font-semibold">Seguimiento de operaciones</h2>
            <div class="grid min-w-0 grid-cols-1 items-start gap-3 {{ $verCobros && $verInventario ? '2xl:grid-cols-2' : '' }}">
                @if ($verCobros)
                    <section class="min-w-0 overflow-hidden rounded border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="border-b border-zinc-200 px-3 py-3 dark:border-zinc-700">
                            <h3 class="text-sm font-semibold">Últimos cobros del período</h3>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Hasta cinco operaciones confirmadas más recientes</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    <tr>
                                        <th scope="col" class="px-3 py-2 font-medium">Operación / fecha</th>
                                        <th scope="col" class="px-3 py-2 font-medium">Cliente</th>
                                        <th scope="col" class="px-3 py-2 font-medium">Caja</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @forelse ($ultimosCobros as $cobro)
                                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                            <td class="px-3 py-2.5">
                                                <div class="font-medium">{{ $cobro['codigo'] }}</div>
                                                <div class="mt-0.5 whitespace-nowrap text-[11px] text-zinc-500 dark:text-zinc-400">{{ $cobro['fecha'] }}</div>
                                            </td>
                                            <td class="px-3 py-2.5">{{ $cobro['cliente'] }}</td>
                                            <td class="px-3 py-2.5">{{ $cobro['caja'] }}</td>
                                            <td class="whitespace-nowrap px-3 py-2.5 text-right font-medium tabular-nums">{{ $moneda($cobro['total']) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="px-3 py-6 text-center text-zinc-500 dark:text-zinc-400">No hay cobros confirmados en este período.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

                @if ($verInventario)
                    <section class="min-w-0 overflow-hidden rounded border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-3 py-3 dark:border-zinc-700">
                            <div>
                                <h3 class="text-sm font-semibold">Alertas de inventario</h3>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $stockBajo }} artículos requieren atención · Hasta 8 prioritarios · Stock actual</p>
                            </div>
                            <a href="{{ route('insumos.index') }}" wire:navigate class="inline-flex h-8 items-center gap-1 rounded border border-zinc-300 px-2.5 text-xs font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">Ver inventario <span aria-hidden="true">→</span></a>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    <tr>
                                        <th scope="col" class="px-3 py-2 font-medium">Artículo</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Disponible</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Mínimo</th>
                                        <th scope="col" class="px-3 py-2 font-medium">Estado</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @forelse ($articulosStockBajo as $articulo)
                                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                            <td class="px-3 py-2.5">
                                                <div class="font-medium">{{ $articulo['nombre'] }}</div>
                                                <div class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">{{ $articulo['codigo'] }} · {{ $articulo['tipo'] }}</div>
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2.5 text-right font-medium tabular-nums">{{ $cantidad($articulo['disponible']) }} {{ $articulo['unidad'] }}</td>
                                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-zinc-500 dark:text-zinc-400">{{ $cantidad($articulo['minimo']) }} {{ $articulo['unidad'] }}</td>
                                            <td class="px-3 py-2.5">
                                                @if ($articulo['agotado'])
                                                    <span class="inline-flex whitespace-nowrap rounded border border-red-200 bg-red-50 px-1.5 py-0.5 text-[11px] font-medium text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-400">Sin disponible</span>
                                                @else
                                                    <span class="inline-flex whitespace-nowrap rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-400">Stock bajo</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="px-3 py-6 text-center text-emerald-700 dark:text-emerald-400">No hay artículos con existencias bajas.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif
            </div>
        </section>
    @endif

    @if (!$verCobros && !$verPedidos && !$verCajas && !$verInventario)
        <section class="rounded border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="text-sm font-semibold">Bienvenido a SisRest</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Utiliza el menú lateral para acceder a los módulos que tienes autorizados.</p>
        </section>
    @endif
</div>
