<div class="space-y-4">

    {{-- Encabezado --}}
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-800 dark:text-zinc-100">
                Dashboard
            </h1>
            <p class="text-sm text-zinc-500">
                Resumen de las operaciones del restaurante
            </p>
        </div>

        @if ($verCobros)
            <div class="flex items-center gap-2">
                <a href="{{ route('dashboard', ['periodo' => 7]) }}" wire:navigate
                    class="border px-3 py-2 text-sm font-medium {{ $periodo === 7
                        ? 'border-zinc-800 bg-zinc-800 text-white dark:border-zinc-200 dark:bg-zinc-200 dark:text-zinc-900'
                        : 'border-zinc-300 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
                    7 días
                </a>

                <a href="{{ route('dashboard', ['periodo' => 30]) }}" wire:navigate
                    class="border px-3 py-2 text-sm font-medium {{ $periodo === 30
                        ? 'border-zinc-800 bg-zinc-800 text-white dark:border-zinc-200 dark:bg-zinc-200 dark:text-zinc-900'
                        : 'border-zinc-300 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
                    30 días
                </a>
            </div>
        @endif

        <a href="{{ route('dashboard') }}"
            class="inline-flex items-center border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800">
            Actualizar
        </a>
    </header>

    @if ($verCobros)
        <form action="{{ route('dashboard') }}" method="GET"
            class="flex flex-wrap items-end gap-3 border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
            <label class="grid gap-1 text-sm">
                <span class="text-zinc-500">Desde</span>
                <input type="date" name="desde" value="{{ $desde }}" max="{{ now()->toDateString() }}"
                    required
                    class="rounded border border-zinc-300 bg-white px-3 py-2 text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
            </label>

            <label class="grid gap-1 text-sm">
                <span class="text-zinc-500">Hasta</span>
                <input type="date" name="hasta" value="{{ $hasta }}" max="{{ now()->toDateString() }}"
                    required
                    class="rounded border border-zinc-300 bg-white px-3 py-2 text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
            </label>

            <button type="submit"
                class="rounded bg-[#2478A0] px-4 py-2 text-sm font-medium text-white hover:opacity-90">
                Aplicar fechas
            </button>

            <a href="{{ route('dashboard') }}" wire:navigate
                class="rounded border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700">
                Restablecer
            </a>
        </form>

        @if ($errorFechas)
            <p class="text-sm text-red-600 dark:text-red-400">
                {{ $errorFechas }}
            </p>
        @endif
    @endif

    {{-- Indicadores --}}
    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">

        @if ($verCobros)
            <article class="border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-sm text-zinc-500">
                    Cobros netos de hoy
                </p>

                <p class="mt-2 text-2xl font-semibold">
                    S/ {{ number_format((float) $cobrosNetosHoy, 2, ',', '.') }}
                </p>

                <p class="mt-1 text-xs text-zinc-500">
                    Cobrado menos devoluciones
                </p>
            </article>

            <article class="border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-sm text-zinc-500">
                    Cobros realizados hoy
                </p>

                <p class="mt-2 text-2xl font-semibold">
                    {{ $cantidadCobros }}
                </p>

                <p class="mt-1 text-xs text-zinc-500">
                    Operaciones confirmadas
                </p>
            </article>
        @endif

        @if ($verPedidos)
            <article class="border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-sm text-zinc-500">
                    Pedidos activos
                </p>

                <p class="mt-2 text-2xl font-semibold">
                    {{ $pedidosActivos }}
                </p>

                <p class="mt-1 text-xs text-zinc-500">
                    Atenciones sin finalizar
                </p>
            </article>
        @endif

        @if ($verCajas)
            <article class="border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-sm text-zinc-500">
                    Cajas abiertas
                </p>

                <p class="mt-2 text-2xl font-semibold">
                    {{ $cajasAbiertas }}
                </p>

                <p class="mt-1 text-xs text-zinc-500">
                    Turnos actualmente abiertos
                </p>
            </article>
        @endif

        @if ($verInventario)
            <article class="border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-sm text-zinc-500">
                    Existencias bajas
                </p>

                <p class="mt-2 text-2xl font-semibold">
                    {{ $stockBajo }}
                </p>

                <p class="mt-1 text-xs text-zinc-500">
                    Artículos que requieren atención
                </p>
            </article>
        @endif
    </section>

    {{-- Gráfico principal --}}
    @if ($verCobros)
        <section class="border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">

            <div class="mb-4">
                <h2 class="font-semibold">
                   Cobros netos {{ $etiquetaPeriodo }}
                </h2>

                <p class="text-xs text-zinc-500">
                    Cobros confirmados menos devoluciones confirmadas
                </p>
            </div>

            <div wire:ignore class="relative h-72 w-full">
                <canvas id="dashboard-grafico-ventas" data-dashboard-chart="ventas"
                    data-serie='@json($grafico)' role="img"
                    aria-label="Gráfico de cobros netos de los últimos siete días"></canvas>
            </div>

        </section>

        {{-- Gráficos secundarios --}}
        <div class="grid gap-3 lg:grid-cols-2">

            {{-- Productos más cobrados --}}
            <section class="min-w-0 border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">

                <div class="mb-4">
                    <h2 class="font-semibold">
                        Productos más cobrados
                    </h2>

                    <p class="text-xs text-zinc-500">
                        Top 5 por unidades {{ $etiquetaPeriodo }}.
                        No descuenta devoluciones.
                    </p>
                </div>

                @if (count($productosMasCobrados['etiquetas']) > 0)
                    <div wire:ignore class="relative h-72 w-full">
                        <canvas data-dashboard-chart="productos" data-serie='@json($productosMasCobrados)'
                            role="img" aria-label="Productos más cobrados"></canvas>
                    </div>
                @else
                    <div class="flex h-72 items-center justify-center text-sm text-zinc-500">
                        Sin productos cobrados en este período.
                    </div>
                @endif
            </section>

            {{-- Medios de pago --}}
            <section class="min-w-0 border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">

                <div class="mb-4">
                    <h2 class="font-semibold">
                        Pagos por medio
                    </h2>

                    <p class="text-xs text-zinc-500">
                        Importes recibidos {{ $etiquetaPeriodo }}.
                        No descuenta devoluciones.
                    </p>
                </div>

                @if (count($mediosPago['etiquetas']) > 0)
                    <div wire:ignore class="relative h-72 w-full">
                        <canvas data-dashboard-chart="medios" data-serie='@json($mediosPago)' role="img"
                            aria-label="Importes recibidos por medio de pago"></canvas>
                    </div>
                @else
                    <div class="flex h-72 items-center justify-center text-sm text-zinc-500">
                        Sin pagos registrados en este período.
                    </div>
                @endif
            </section>
        </div>
        {{-- Comparación de períodos --}}
        <section class="border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="font-semibold">Comparación de cobros netos</h2>

            <p class="mt-1 text-xs text-zinc-500">
                Período anterior: {{ $periodoAnterior }}
            </p>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="border border-zinc-200 p-3 dark:border-zinc-700">
                    <p class="text-xs text-zinc-500">Período seleccionado</p>
                    <p class="mt-2 text-xl font-semibold">
                        S/ {{ number_format((float) $comparacion['actual'], 2, ',', '.') }}
                    </p>
                </div>

                <div class="border border-zinc-200 p-3 dark:border-zinc-700">
                    <p class="text-xs text-zinc-500">Período anterior</p>
                    <p class="mt-2 text-xl font-semibold">
                        S/ {{ number_format((float) $comparacion['anterior'], 2, ',', '.') }}
                    </p>
                </div>

                <div class="border border-zinc-200 p-3 dark:border-zinc-700">
                    <p class="text-xs text-zinc-500">Diferencia</p>
                    <p class="mt-2 text-xl font-semibold">
                        S/ {{ number_format((float) $comparacion['diferencia'], 2, ',', '.') }}
                    </p>
                </div>
            </div>
        </section>

        {{-- Últimas operaciones --}}
        <section class="overflow-hidden border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <h2 class="font-semibold">Últimos cobros del período</h2>
                <p class="mt-1 text-xs text-zinc-500">
                    Cinco operaciones confirmadas más recientes
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3">Código</th>
                            <th class="px-4 py-3">Fecha</th>
                            <th class="px-4 py-3">Cliente</th>
                            <th class="px-4 py-3">Caja</th>
                            <th class="px-4 py-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse($ultimosCobros as $cobro)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $cobro['codigo'] }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $cobro['fecha'] }}</td>
                                <td class="px-4 py-3">{{ $cobro['cliente'] }}</td>
                                <td class="px-4 py-3">{{ $cobro['caja'] }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    S/ {{ number_format((float) $cobro['total'], 2, ',', '.') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-zinc-500">
                                    No hay cobros confirmados en este período.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if (!$verCobros && !$verPedidos && !$verCajas && !$verInventario)
        <section class="border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="font-semibold">
                Bienvenido a SisRest
            </h2>

            <p class="mt-2 text-sm text-zinc-500">
                Utiliza el menú lateral para acceder a los módulos
                que tienes autorizados.
            </p>
        </section>
    @endif

</div>
