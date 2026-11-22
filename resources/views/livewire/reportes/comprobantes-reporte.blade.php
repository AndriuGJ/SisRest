<div class="space-y-6">

    @php
        $moneda = fn($valor) => 'S/ ' . number_format((float) $valor, 2, '.', ',');

        $hoy = now(config('app.timezone'))->toDateString();
    @endphp

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-wide text-zinc-500">
                Reportes
            </p>

            <flux:heading size="xl" level="1">
                Comprobantes
            </flux:heading>


            <flux:text class="mt-1">
                Consulta las emisiones electrónicas y sus estados ante SUNAT.
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
            class="grid items-end gap-4 border-b border-zinc-200 p-4 sm:grid-cols-2 xl:grid-cols-4 dark:border-zinc-700">

            <flux:input wire:model.live.debounce.300ms="search" label="Buscar" placeholder="Serie, número o cliente"
                maxlength="100" />

            <flux:select wire:model.live="tipoFilter" label="Tipo de comprobante">
                <option value="">Todos</option>

                @foreach ($tipos as $valor => $nombre)
                    <option value="{{ $valor }}">
                        {{ $nombre }}
                    </option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="stateFilter" label="Estado del documento">
                <option value="">Todos</option>
                <option value="emitido">Emitido</option>
                <option value="anulado">Anulado</option>
            </flux:select>

            <flux:select wire:model.live="sunatFilter" label="Estado SUNAT">
                <option value="">Todos</option>

                @foreach ($estadosSunat as $valor => $nombre)
                    <option value="{{ $valor }}">
                        {{ $nombre }}
                    </option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="entornoFilter" label="Entorno">
                <option value="">Todos</option>
                <option value="beta">Beta</option>
                <option value="produccion">Producción</option>
            </flux:select>

            <flux:input type="date" wire:model.live="from" label="Desde" max="{{ $hoy }}" />

            <flux:input type="date" wire:model.live="to" label="Hasta" max="{{ $hoy }}" />

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
                        Documentos
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $resumen['documentos'] }}
                    </p>

                    <p class="mt-1 text-xs text-zinc-500">
                        {{ $resumen['pendientes'] }} por resolver ·
                        {{ $resumen['rechazados'] }} rechazados
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Aceptados / observados
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">
                        {{ $resumen['aceptados'] + $resumen['observados'] }}
                    </p>

                    <p class="mt-1 text-xs text-zinc-500">
                        {{ $resumen['aceptados'] }} aceptados ·
                        {{ $resumen['observados'] }} observados
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Importe emitido Producción
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $moneda($resumen['importe_produccion']) }}
                    </p>

                    <p class="mt-1 text-xs text-zinc-500">
                        Documentos emitidos en entorno real
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Importe emitido Beta
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $moneda($resumen['importe_beta']) }}
                    </p>

                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">
                        Documentos de prueba
                    </p>
                </div>

            </div>

            {{-- Tabla --}}
            <div class="overflow-x-auto">

                <table class="w-full text-left text-sm">

                    <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-3">
                                Comprobante
                            </th>

                            <th class="px-4 py-3">
                                Fecha
                            </th>

                            <th class="px-4 py-3">
                                Cliente
                            </th>

                            <th class="px-4 py-3">
                                Entorno
                            </th>

                            <th class="px-4 py-3">
                                Estado
                            </th>

                            <th class="px-4 py-3">
                                Estado SUNAT
                            </th>

                            <th class="px-4 py-3 text-right">
                                Total
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">

                        @forelse ($comprobantes as $comprobante)
                            @php
                                $tipoCodigo = $comprobante->serie?->tipoComprobante?->codigo;

                                $tipoNombre =
                                    $tipos[$tipoCodigo] ??
                                    ($comprobante->serie?->tipoComprobante?->nombre ?? 'Comprobante');

                                $numero = \App\Services\Facturacion\XmlFiscal::numero($comprobante);
                            @endphp

                            <tr wire:key="reporte-comprobante-{{ $comprobante->id }}">

                                <td class="px-4 py-3">
                                    <p class="font-medium">
                                        {{ $numero }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $tipoNombre }}
                                    </p>
                                </td>

                                <td class="whitespace-nowrap px-4 py-3">
                                    {{ $comprobante->fecha_emision->format('d/m/Y') }}
                                </td>

                                <td class="px-4 py-3">
                                    <p class="font-medium">
                                        {{ $comprobante->receptor['nombre'] ?? 'Sin nombre' }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $comprobante->receptor['numero'] ?? '—' }}
                                    </p>
                                </td>

                                <td class="px-4 py-3">
                                    @if ($comprobante->entorno === 'beta')
                                        <span class="text-xs font-medium text-amber-700 dark:text-amber-400">
                                            Beta
                                        </span>
                                    @else
                                        <span class="text-xs font-medium">
                                            Producción
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    <x-estado :estado="$comprobante->estado->value" size="sm">
                                        {{ ucfirst($comprobante->estado->value) }}
                                    </x-estado>
                                </td>

                                <td class="px-4 py-3">
                                    <x-estado :estado="$comprobante->estado_sunat->value" size="sm">
                                        {{ $estadosSunat[$comprobante->estado_sunat->value] ?? ucfirst($comprobante->estado_sunat->value) }}
                                    </x-estado>
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">
                                    {{ $moneda($comprobante->total) }}
                                </td>

                            </tr>

                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center text-zinc-500">
                                    No hay comprobantes con estos filtros.
                                </td>
                            </tr>
                        @endforelse

                    </tbody>

                </table>

            </div>

            {{-- Información --}}
            <div class="border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700">
                Los importes son de comprobantes emitidos y se separan por entorno.
                El estado SUNAT es independiente del pago de la venta.
                Los documentos Beta no tienen validez fiscal.
            </div>

            {{-- Paginación --}}
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $comprobantes->links() }}
            </div>

        @endif

    </div>

</div>
