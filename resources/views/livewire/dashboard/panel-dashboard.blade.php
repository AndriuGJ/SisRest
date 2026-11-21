
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

        <a
            href="{{ route('dashboard') }}"
            class="inline-flex items-center border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
        >
            Actualizar
        </a>
    </header>

    {{-- Indicadores --}}
    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">

        @if($verCobros)
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

        @if($verPedidos)
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

        @if($verCajas)
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

        @if($verInventario)
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
    @if($verCobros)
        <section class="border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">

            <div class="mb-4">
                <h2 class="font-semibold">
                    Cobros netos de los últimos 7 días
                </h2>

                <p class="text-xs text-zinc-500">
                    Cobros confirmados menos devoluciones confirmadas
                </p>
            </div>

            <div wire:ignore class="relative h-72 w-full">
                <canvas
                    id="dashboard-grafico-ventas"
                    data-dashboard-ventas
                    data-serie='@json($grafico)'
                    role="img"
                    aria-label="Gráfico de cobros netos de los últimos siete días"
                ></canvas>
            </div>

        </section>
    @endif

    @if(! $verCobros && ! $verPedidos && ! $verCajas && ! $verInventario)
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
