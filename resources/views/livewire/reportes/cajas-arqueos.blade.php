<div class="space-y-6">

    @php
        $moneda = fn($valor) => $valor === null ? '—' : 'S/ ' . number_format((float) $valor, 2, '.', ',');

        $fecha = fn($valor) => $valor ? \Carbon\CarbonImmutable::parse($valor)->format('d/m/Y H:i') : '—';
    @endphp

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-wide text-zinc-500">
                Reportes
            </p>

            <flux:heading size="xl" level="1">
                Cajas y arqueos
            </flux:heading>

            <flux:text class="mt-1">
                Consulta los turnos de caja, movimientos de efectivo y resultados de cierre.
            </flux:text>
        </div>

        @include('livewire.reportes.partials.botones-exportacion')

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
            class="grid items-end gap-4 border-b border-zinc-200 p-4 sm:grid-cols-2 xl:grid-cols-3 dark:border-zinc-700">

            <flux:input wire:model.live.debounce.300ms="search" label="Buscar" placeholder="Caja o responsable" />

            <flux:select wire:model.live="cajaId" label="Caja">
                <option value="">Todas</option>

                @foreach ($cajas as $caja)
                    <option value="{{ $caja->id }}">
                        {{ $caja->nombre }}
                    </option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="stateFilter" label="Estado">
                <option value="">Todos</option>
                <option value="abierta">Abiertas</option>
                <option value="cerrada">Cerradas</option>
            </flux:select>

            <flux:input type="date" wire:model.live="from" label="Apertura desde"
                max="{{ now()->toDateString() }}" />

            <flux:input type="date" wire:model.live="to" label="Apertura hasta" max="{{ now()->toDateString() }}" />

            <flux:button wire:click="clearFilters" variant="ghost">
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
                        Turnos
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $resumen['turnos'] }}
                    </p>

                    <p class="mt-1 text-xs text-zinc-500">
                        {{ $resumen['abiertas'] }} abiertos /
                        {{ $resumen['cerradas'] }} cerrados
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Cobros netos
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $moneda($resumen['neto_cobrado']) }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Faltantes al cierre
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums text-red-600 dark:text-red-400">
                        {{ $moneda($resumen['faltantes']) }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Sobrantes al cierre
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                        {{ $moneda($resumen['sobrantes']) }}
                    </p>
                </div>

            </div>

            {{-- Tabla --}}
            <div class="overflow-x-auto">

                <table class="w-full text-left text-sm">

                    <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-3">
                                Caja / Responsable
                            </th>

                            <th class="px-4 py-3">
                                Apertura / Cierre
                            </th>

                            <th class="px-4 py-3">
                                Estado
                            </th>

                            <th class="px-4 py-3 text-right">
                                Fondo inicial
                            </th>

                            <th class="px-4 py-3 text-right">
                                Movimientos
                            </th>

                            <th class="px-4 py-3 text-right">
                                Cobros netos
                            </th>

                            <th class="px-4 py-3 text-right">
                                Esperado
                            </th>

                            <th class="px-4 py-3 text-right">
                                Contado
                            </th>

                            <th class="px-4 py-3 text-right">
                                Diferencia
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">

                        @forelse ($turnos as $turno)
                            @php
                                $cerrada = $turno->estado === 'cerrada';

                                $diferencia = $cerrada ? $turno->diferencia : null;

                                $colorDiferencia = match (true) {
                                    $diferencia === null => 'text-zinc-500',
                                    bccomp((string) $diferencia, '0', 2) < 0 => 'text-red-600 dark:text-red-400',
                                    default => 'text-emerald-600 dark:text-emerald-400',
                                };
                            @endphp

                            <tr wire:key="reporte-turno-{{ $turno->id }}">

                                <td class="px-4 py-3">
                                    <p class="font-medium">
                                        {{ $turno->caja }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $turno->responsable }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        Turno #{{ $turno->id }}
                                    </p>
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-xs">
                                    <p>
                                        {{ $fecha($turno->abierta_at) }}
                                    </p>

                                    <p class="mt-1 text-zinc-500">
                                        {{ $fecha($turno->cerrada_at) }}
                                    </p>
                                </td>

                                <td class="px-4 py-3">

                                    <x-estado :estado="$turno->estado" size="sm">
                                        {{ $cerrada ? 'Cerrada' : 'Abierta' }}
                                    </x-estado>

                                    <p class="mt-1 whitespace-nowrap text-xs text-zinc-500">
                                        Arqueos parciales: {{ $turno->arqueos_parciales }}
                                    </p>

                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                    {{ $moneda($turno->fondo_inicial) }}
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right text-xs tabular-nums">

                                    <p class="text-emerald-600 dark:text-emerald-400">
                                        + {{ $moneda($turno->ingresos ?? 0) }}
                                    </p>

                                    <p class="mt-1 text-red-600 dark:text-red-400">
                                        − {{ $moneda($turno->egresos ?? 0) }}
                                    </p>

                                    <p class="mt-1 text-zinc-500">
                                        R: {{ $moneda($turno->retiros ?? 0) }}
                                    </p>

                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">

                                    <p class="font-medium">
                                        {{ $moneda($turno->neto_cobrado) }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        Cobrado: {{ $moneda($turno->cobrado) }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        Devuelto: {{ $moneda($turno->devuelto) }}
                                    </p>

                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">
                                    {{ $moneda($turno->esperado) }}
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                    {{ $moneda($cerrada ? $turno->contado : null) }}
                                </td>

                                <td class="px-4 py-3 text-right">

                                    <p class="whitespace-nowrap font-medium tabular-nums {{ $colorDiferencia }}">
                                        {{ $moneda($diferencia) }}
                                    </p>

                                    @if ($diferencia !== null)
                                        <p class="mt-1 text-xs {{ $colorDiferencia }}">
                                            {{ bccomp((string) $diferencia, '0', 2) < 0
                                                ? 'Faltante'
                                                : (bccomp((string) $diferencia, '0', 2) > 0
                                                    ? 'Sobrante'
                                                    : 'Sin diferencia') }}
                                        </p>
                                    @endif

                                    @if ($cerrada && $turno->observaciones_cierre)
                                        <p class="mt-1 max-w-48 break-words text-xs text-zinc-500">
                                            {{ $turno->observaciones_cierre }}
                                        </p>
                                    @endif

                                </td>

                            </tr>

                        @empty

                            <tr>
                                <td colspan="9" class="px-4 py-10 text-center text-zinc-500">
                                    No hay turnos con los filtros seleccionados.
                                </td>
                            </tr>
                        @endforelse

                    </tbody>

                </table>

            </div>

            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <p class="text-xs text-zinc-500">
                    Los movimientos corresponden únicamente al efectivo físico.
                    R: retiros. Las diferencias corresponden al arqueo final.
                </p>
            </div>

            {{-- Paginación --}}
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $turnos->links() }}
            </div>

        @endif

    </div>

</div>
