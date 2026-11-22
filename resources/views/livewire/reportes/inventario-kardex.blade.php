
<div class="space-y-6">

    @php
        $cantidad = fn ($valor) =>
            \App\Support\Cantidad::mostrar((string) $valor);

        $moneda = fn ($valor) =>
            'S/ '.number_format((float) $valor, 2, '.', ',');
    @endphp

    {{-- Encabezado --}}
    <div>
        <p class="text-xs uppercase tracking-wide text-zinc-500">
            Reportes
        </p>

        <flux:heading size="xl" level="1">
            Inventario y Kardex
        </flux:heading>

        <flux:text class="mt-1">
            Consulta las existencias y los movimientos registrados.
        </flux:text>
    </div>

    {{-- Contenedor --}}
    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">

        {{-- Selección de sección --}}
        <div class="flex flex-wrap gap-2 border-b border-zinc-200 p-4 dark:border-zinc-700">

            <flux:button
                wire:click="$set('vista', 'existencias')"
                :variant="$vista === 'existencias' ? 'primary' : 'outline'"
                icon="archive-box"
            >
                Existencias
            </flux:button>

            <flux:button
                wire:click="$set('vista', 'kardex')"
                :variant="$vista === 'kardex' ? 'primary' : 'outline'"
                icon="arrows-right-left"
            >
                Kardex
            </flux:button>

        </div>

        {{-- Filtros --}}
        <div class="grid items-end gap-4 border-b border-zinc-200 p-4 sm:grid-cols-2 xl:grid-cols-4 dark:border-zinc-700">

            <flux:input
                wire:model.live.debounce.300ms="search"
                label="Buscar artículo"
                placeholder="Nombre o código"
                maxlength="100"
            />

            <flux:select
                wire:model.live="tipoArticulo"
                label="Tipo de artículo"
            >
                <option value="">Todos</option>

                @foreach ($tiposArticulo as $valor => $nombre)
                    <option value="{{ $valor }}">
                        {{ $nombre }}
                    </option>
                @endforeach
            </flux:select>

            @if ($vista === 'existencias')

                <flux:select
                    wire:model.live="stockFilter"
                    label="Disponibilidad"
                >
                    <option value="">Todas</option>
                    <option value="con_stock">Con disponible</option>
                    <option value="bajo">Bajo el mínimo</option>
                    <option value="agotado">Sin disponible</option>
                </flux:select>

            @else

                <flux:select
                    wire:model.live="movimientoFilter"
                    label="Tipo de movimiento"
                >
                    <option value="">Todos</option>

                    @foreach ($tiposMovimiento as $valor => $nombre)
                        <option value="{{ $valor }}">
                            {{ $nombre }}
                        </option>
                    @endforeach
                </flux:select>

                <flux:input
                    type="date"
                    wire:model.live="from"
                    label="Desde"
                    max="{{ now()->toDateString() }}"
                />

                <flux:input
                    type="date"
                    wire:model.live="to"
                    label="Hasta"
                    max="{{ now()->toDateString() }}"
                />

            @endif

            <flux:button
                wire:click="clearFilters"
                variant="ghost"
            >
                Limpiar
            </flux:button>

        </div>

        @if ($errorFiltros)

            <p class="p-4 text-sm text-red-600 dark:text-red-400">
                {{ $errorFiltros }}
            </p>

        @else

            {{-- Resumen --}}
            <div class="grid grid-cols-2 gap-4 border-b border-zinc-200 p-4 lg:grid-cols-4 dark:border-zinc-700">

                @if ($vista === 'existencias')

                    <div>
                        <p class="text-xs text-zinc-500">Artículos</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">
                            {{ $resumen['articulos'] }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-zinc-500">Bajo el mínimo</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-amber-700 dark:text-amber-400">
                            {{ $resumen['bajo_minimo'] }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-zinc-500">Sin disponible</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-red-600 dark:text-red-400">
                            {{ $resumen['sin_disponible'] }}
                        </p>
                    </div>

                    @if ($verCostos)
                        <div>
                            <p class="text-xs text-zinc-500">
                                Valorización actual
                            </p>
                            <p class="mt-1 text-lg font-semibold tabular-nums">
                                {{ $moneda($resumen['valorizacion']) }}
                            </p>
                        </div>
                    @endif

                @else

                    <div>
                        <p class="text-xs text-zinc-500">Movimientos</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">
                            {{ $resumen['movimientos'] }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-zinc-500">Entradas</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">
                            {{ $resumen['entradas'] }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-zinc-500">Salidas</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-red-600 dark:text-red-400">
                            {{ $resumen['salidas'] }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-zinc-500">Artículos afectados</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">
                            {{ $resumen['articulos'] }}
                        </p>
                    </div>

                @endif

            </div>

            {{-- Tabla de existencias --}}
            @if ($vista === 'existencias')

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">

                        <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-900">
                            <tr>
                                <th class="px-4 py-3">Artículo</th>
                                <th class="px-4 py-3">Unidad</th>
                                <th class="px-4 py-3 text-right">Actual</th>
                                <th class="px-4 py-3 text-right">Reservado</th>
                                <th class="px-4 py-3 text-right">Disponible</th>
                                <th class="px-4 py-3 text-right">Mínimo</th>

                                @if ($verCostos)
                                    <th class="px-4 py-3 text-right">Valorización</th>
                                @endif

                                <th class="px-4 py-3">Estado</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">

                            @forelse ($registros as $articulo)

                                @php
                                    $bajo = bccomp(
                                        $articulo->stock_minimo,
                                        '0',
                                        4
                                    ) > 0 && bccomp(
                                        $articulo->disponible,
                                        $articulo->stock_minimo,
                                        4
                                    ) <= 0;

                                    $sinDisponible = bccomp(
                                        $articulo->disponible,
                                        '0',
                                        4
                                    ) <= 0;
                                @endphp

                                <tr wire:key="inventario-articulo-{{ $articulo->id }}">

                                    <td class="px-4 py-3">
                                        <p class="font-medium">
                                            {{ $articulo->nombre }}
                                        </p>

                                        <p class="mt-1 text-xs text-zinc-500">
                                            {{ $articulo->codigo }}
                                            ·
                                            {{ $tiposArticulo[$articulo->tipo->value] ?? 'Artículo' }}
                                        </p>
                                    </td>

                                    <td class="px-4 py-3">
                                        {{ $articulo->unidadMedida->codigo }}
                                    </td>

                                    <td class="px-4 py-3 text-right tabular-nums">
                                        {{ $cantidad($articulo->stock_actual) }}
                                    </td>

                                    <td class="px-4 py-3 text-right tabular-nums">
                                        {{ $cantidad($articulo->stock_reservado) }}
                                    </td>

                                    <td class="px-4 py-3 text-right font-medium tabular-nums {{ $sinDisponible ? 'text-red-600 dark:text-red-400' : '' }}">
                                        {{ $cantidad($articulo->disponible) }}
                                    </td>

                                    <td class="px-4 py-3 text-right tabular-nums">
                                        {{ $cantidad($articulo->stock_minimo) }}
                                    </td>

                                    @if ($verCostos)
                                        <td class="px-4 py-3 text-right tabular-nums">
                                            {{ $moneda(bcmul(
                                                $articulo->stock_actual,
                                                $articulo->costo_promedio,
                                                6
                                            )) }}
                                        </td>
                                    @endif

                                    <td class="px-4 py-3">
                                        <x-estado :estado="$articulo->activo ? 'activo' : 'inactivo'" size="sm">
                                            {{ $articulo->activo ? 'Activo' : 'Inactivo' }}
                                        </x-estado>

                                        @if ($bajo)
                                            <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                                Bajo el mínimo
                                            </p>
                                        @endif
                                    </td>

                                </tr>

                            @empty

                                <tr>
                                    <td
                                        colspan="{{ $verCostos ? 8 : 7 }}"
                                        class="px-4 py-10 text-center text-zinc-500"
                                    >
                                        No hay artículos con estos filtros.
                                    </td>
                                </tr>

                            @endforelse

                        </tbody>
                    </table>
                </div>

            @else

                {{-- Tabla de Kardex --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">

                        <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-900">
                            <tr>
                                <th class="px-4 py-3">Fecha / Usuario</th>
                                <th class="px-4 py-3">Artículo</th>
                                <th class="px-4 py-3">Movimiento</th>
                                <th class="px-4 py-3">Origen</th>
                                <th class="px-4 py-3 text-right">Entrada</th>
                                <th class="px-4 py-3 text-right">Salida</th>
                                <th class="px-4 py-3 text-right">Anterior</th>
                                <th class="px-4 py-3 text-right">Posterior</th>

                                @if ($verCostos)
                                    <th class="px-4 py-3 text-right">Costo unitario</th>
                                @endif
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">

                            @forelse ($registros as $movimiento)

                                @php
                                    $entrada = bccomp(
                                        (string) $movimiento->cantidad,
                                        '0',
                                        4
                                    ) > 0;

                                    $magnitud = $entrada
                                        ? (string) $movimiento->cantidad
                                        : bcsub('0', (string) $movimiento->cantidad, 4);

                                    $origen = match ($movimiento->origen_type) {
                                        \App\Models\Compra::class => 'Compra',
                                        \App\Models\Preparacion::class => 'Preparación',
                                        \App\Models\Pedido::class => 'Pedido',
                                        default => 'Movimiento',
                                    };
                                @endphp

                                <tr wire:key="reporte-kardex-{{ $movimiento->id }}">

                                    <td class="whitespace-nowrap px-4 py-3">
                                        {{ $movimiento->registrado_at->format('d/m/Y H:i') }}

                                        <p class="mt-1 text-xs text-zinc-500">
                                            {{ $movimiento->usuario->name }}
                                        </p>
                                    </td>

                                    <td class="px-4 py-3">
                                        <p class="font-medium">
                                            {{ $movimiento->articulo->nombre }}
                                        </p>

                                        <p class="mt-1 text-xs text-zinc-500">
                                            {{ $movimiento->articulo->codigo }}
                                            ·
                                            {{ $movimiento->articulo->unidadMedida->codigo }}
                                        </p>
                                    </td>

                                    <td class="px-4 py-3">
                                        {{ $tiposMovimiento[$movimiento->tipo->value] ?? $movimiento->tipo->value }}

                                        @if ($movimiento->motivo)
                                            <p class="mt-1 max-w-48 break-words text-xs text-zinc-500">
                                                {{ $movimiento->motivo }}
                                            </p>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3">
                                        {{ $origen }}

                                        @if ($movimiento->origen_id)
                                            <p class="mt-1 text-xs text-zinc-500">
                                                #{{ $movimiento->origen_id }}
                                            </p>
                                        @endif
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400">
                                        {{ $entrada ? $cantidad($magnitud) : '—' }}
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-red-600 dark:text-red-400">
                                        {{ $entrada ? '—' : $cantidad($magnitud) }}
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                        {{ $cantidad($movimiento->saldo_anterior) }}
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">
                                        {{ $cantidad($movimiento->saldo_posterior) }}
                                    </td>

                                    @if ($verCostos)
                                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
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
                                        class="px-4 py-10 text-center text-zinc-500"
                                    >
                                        No hay movimientos con estos filtros.
                                    </td>
                                </tr>

                            @endforelse

                        </tbody>
                    </table>
                </div>

            @endif

            {{-- Información y paginación --}}
            <div class="border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700">

                @if ($vista === 'existencias')
                    Las existencias corresponden al stock actual.
                    Disponible = actual − reservado.
                    La valorización utiliza el costo promedio actual.
                @else
                    El Kardex utiliza los movimientos registrados durante el período.
                    Los saldos anterior y posterior son los guardados en cada movimiento.
                @endif

            </div>

            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $registros->links() }}
            </div>

        @endif

    </div>

</div>
