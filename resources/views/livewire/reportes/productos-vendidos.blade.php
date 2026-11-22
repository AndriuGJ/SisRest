
<div class="space-y-6">

    @php
        $moneda = fn ($valor) =>
            'S/ '.number_format((float) $valor, 2, '.', ',');

        $cantidad = fn ($valor) =>
            rtrim(rtrim(number_format((float) $valor, 4, '.', ','), '0'), '.');
    @endphp

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-wide text-zinc-500">
                Reportes
            </p>

            <flux:heading size="xl" level="1">
                Productos vendidos
            </flux:heading>

            <flux:text class="mt-1">
                Consulta las cantidades, descuentos e importes de los productos cobrados.
            </flux:text>
        </div>
    </div>

    {{-- Contenedor principal --}}
    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">

        {{-- Filtros --}}
        <div class="grid items-end gap-4 border-b border-zinc-200 p-4 sm:grid-cols-2 xl:grid-cols-4 dark:border-zinc-700">

            <flux:input
                wire:model.live.debounce.300ms="search"
                label="Buscar producto"
                placeholder="Nombre o código"
            />

            <flux:select
                wire:model.live="categoriaId"
                label="Categoría"
            >
                <option value="">Todas</option>

                @foreach ($categorias as $categoria)
                    <option value="{{ $categoria->id }}">
                        {{ $categoria->nombre }}
                    </option>
                @endforeach
            </flux:select>

            <flux:select
                wire:model.live="cajaId"
                label="Caja"
            >
                <option value="">Todas</option>

                @foreach ($cajas as $caja)
                    <option value="{{ $caja->id }}">
                        {{ $caja->nombre }}
                    </option>
                @endforeach
            </flux:select>

            <flux:select
                wire:model.live="modalidadFilter"
                label="Modalidad"
            >
                <option value="">Todas</option>

                @foreach ($modalidades as $valor => $nombre)
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

                <div>
                    <p class="text-xs text-zinc-500">
                        Productos
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $resumen['productos'] }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Cantidad neta
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $cantidad($resumen['cantidad_neta']) }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Descuentos aplicados
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $moneda($resumen['descuento']) }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Importe neto
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums {{ bccomp($resumen['importe_neto'], '0', 2) < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                        {{ $moneda($resumen['importe_neto']) }}
                    </p>
                </div>

            </div>

            {{-- Tabla --}}
            <div class="overflow-x-auto">

                <table class="w-full text-left text-sm">

                    <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-3">
                                Producto
                            </th>

                            <th class="px-4 py-3 text-right">
                                Cobrados
                            </th>

                            <th class="px-4 py-3 text-right">
                                Devueltos
                            </th>

                            <th class="px-4 py-3 text-right">
                                Cantidad neta
                            </th>

                            <th class="px-4 py-3 text-right">
                                Descuento
                            </th>

                            <th class="px-4 py-3 text-right">
                                Importe cobrado
                            </th>

                            <th class="px-4 py-3 text-right">
                                Importe devuelto
                            </th>

                            <th class="px-4 py-3 text-right">
                                Importe neto
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">

                        @forelse ($productos as $producto)

                            <tr wire:key="reporte-producto-{{ $producto->producto_id }}">

                                <td class="px-4 py-3">

                                    <p class="font-medium">
                                        {{ $producto->nombre }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $producto->codigo }}
                                        ·
                                        {{ $producto->categoria ?? 'Sin categoría' }}
                                    </p>

                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                    {{ $cantidad($producto->cantidad_cobrada) }}
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums {{ bccomp((string) $producto->cantidad_devuelta, '0', 4) > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                    {{ $cantidad($producto->cantidad_devuelta) }}
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">
                                    {{ $cantidad($producto->cantidad_neta) }}
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                    {{ $moneda($producto->descuento) }}
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                    {{ $moneda($producto->importe_cobrado) }}
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums {{ bccomp((string) $producto->importe_devuelto, '0', 2) > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                    {{ $moneda($producto->importe_devuelto) }}
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums {{ bccomp((string) $producto->importe_neto, '0', 2) < 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                    {{ $moneda($producto->importe_neto) }}
                                </td>

                            </tr>

                        @empty

                            <tr>
                                <td colspan="8" class="px-4 py-10 text-center text-zinc-500">
                                    No hay productos con estos filtros.
                                </td>
                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

            {{-- Paginación --}}
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $productos->links() }}
            </div>

        @endif

    </div>

</div>
