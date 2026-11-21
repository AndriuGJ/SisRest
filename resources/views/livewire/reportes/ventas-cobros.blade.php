<div class="space-y-6">

    @php
        $moneda = fn($valor) => 'S/ ' . number_format((float) $valor, 2, '.', ',');
    @endphp

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-center justify-between gap-4">

        <div>
            <p class="text-xs uppercase tracking-wide text-zinc-500">
                Reportes
            </p>

            <flux:heading size="xl" level="1">
                Ventas y cobros
            </flux:heading>


            <flux:text class="mt-1">
                Consulta los cobros y devoluciones del restaurante.
            </flux:text>
        </div>

        @can('reportes.exportar')
            <flux:button variant="outline" icon="document-arrow-down" wire:click="exportarPdf" wire:loading.attr="disabled"
                :disabled="(bool) $errorFiltros">
                <span wire:loading.remove wire:target="exportarPdf">
                    Exportar PDF
                </span>

                <span wire:loading wire:target="exportarPdf">
                    Generando PDF...
                </span>
            </flux:button>
        @endcan

    </div>

    @error('exportacion')
        <p class="text-sm text-red-600 dark:text-red-400">
            {{ $message }}
        </p>
    @enderror

    {{-- Contenedor principal --}}

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">

        {{-- Filtros --}}
        <div
            class="grid items-end gap-4 border-b border-zinc-200 p-4 sm:grid-cols-2 xl:grid-cols-4 dark:border-zinc-700">

            <flux:input wire:model.live.debounce.300ms="search" label="Buscar operación"
                placeholder="Código, pedido o cliente" />

            <flux:select wire:model.live="cajaId" label="Caja">
                <option value="">Todas</option>

                @foreach ($cajas as $caja)
                    <option value="{{ $caja->id }}">
                        {{ $caja->nombre }}
                    </option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="modalidadFilter" label="Modalidad">
                <option value="">Todas</option>

                @foreach ($modalidades as $valor => $nombre)
                    <option value="{{ $valor }}">
                        {{ $nombre }}
                    </option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="tipoFilter" label="Operación">
                <option value="">Todas</option>
                <option value="cobro">Cobros</option>
                <option value="devolucion">Devoluciones</option>
            </flux:select>

            <flux:input type="date" wire:model.live="from" label="Desde" max="{{ now()->toDateString() }}" />

            <flux:input type="date" wire:model.live="to" label="Hasta" max="{{ now()->toDateString() }}" />

            <flux:button wire:click="clearFilters" variant="ghost">
                Limpiar
            </flux:button>

        </div>

        @if ($errorFiltros)

            <div class="p-4 text-sm text-red-600 dark:text-red-400">
                {{ $errorFiltros }}
            </div>
        @else
            {{-- Resumen de las operaciones --}}
            <div class="grid grid-cols-2 gap-4 border-b border-zinc-200 p-4 lg:grid-cols-4 dark:border-zinc-700">

                <div>
                    <p class="text-xs text-zinc-500">
                        Total cobrado
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $moneda($resumen['cobrado']) }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Devoluciones
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums text-red-600 dark:text-red-400">
                        {{ $moneda($resumen['devuelto']) }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Cobros netos
                    </p>

                    <p
                        class="mt-1 text-lg font-semibold tabular-nums {{ bccomp($resumen['neto'], '0', 2) < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                        {{ $moneda($resumen['neto']) }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Operaciones
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $resumen['operaciones'] }}
                    </p>
                </div>

            </div>

            {{-- Tabla --}}
            <div class="overflow-x-auto">

                <table class="w-full text-left text-sm">

                    <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-3">
                                Código / Fecha
                            </th>

                            <th class="px-4 py-3">
                                Cliente
                            </th>

                            <th class="px-4 py-3">
                                Operación
                            </th>

                            <th class="px-4 py-3">
                                Modalidad
                            </th>

                            <th class="px-4 py-3">
                                Caja
                            </th>

                            <th class="px-4 py-3 text-right">
                                Importe
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">

                        @forelse ($operaciones as $operacion)
                            <tr wire:key="reporte-{{ $operacion->tipo }}-{{ $operacion->operacion_id }}">

                                <td class="px-4 py-3">

                                    <p class="max-w-64 break-all font-medium">
                                        {{ $operacion->codigo }}
                                    </p>

                                    <p class="mt-1 whitespace-nowrap text-xs text-zinc-500">
                                        {{ \Carbon\CarbonImmutable::parse($operacion->fecha)->format('d/m/Y H:i') }}
                                    </p>

                                    @if ($operacion->tipo === 'devolucion')
                                        <p class="mt-1 text-xs text-zinc-500">
                                            Cobro: {{ $operacion->codigo_cobro }}
                                        </p>
                                    @endif

                                </td>

                                <td class="px-4 py-3">
                                    <p>
                                        {{ $operacion->cliente ?: 'Sin cliente' }}
                                    </p>
                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $operacion->pedido }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($operacion->tipo === 'cobro')
                                        <span
                                            class="inline-flex rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-400">
                                            Cobro
                                        </span>
                                    @else
                                        <span
                                            class="inline-flex rounded border border-red-200 bg-red-50 px-2 py-1 text-xs font-medium text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-400">
                                            Devolución
                                        </span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    {{ $modalidades[$operacion->modalidad] ?? 'Otra' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    {{ $operacion->caja }}
                                </td>
                                <td
                                    class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums {{ $operacion->tipo === 'devolucion' ? 'text-red-600 dark:text-red-400' : '' }}">
                                    {{ $moneda($operacion->importe) }}
                                </td>

                            </tr>

                        @empty

                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-zinc-500">
                                    No hay operaciones con estos filtros.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Paginación --}}
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $operaciones->links() }}
            </div>
        @endif
    </div>
</div>
