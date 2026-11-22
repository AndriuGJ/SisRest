
<div class="space-y-6">

    @php
        $moneda = fn ($valor) =>
            $valor === null
                ? '—'
                : 'S/ '.number_format((float) $valor, 2, '.', ',');

        $fecha = fn ($valor) =>
            $valor
                ? \Carbon\CarbonImmutable::parse($valor)->format('d/m/Y')
                : '—';

        $hoy = now(config('app.timezone'))->toDateString();
    @endphp

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-wide text-zinc-500">
                Reportes
            </p>

            <flux:heading size="xl" level="1">
                Compras
            </flux:heading>

            <flux:text class="mt-1">
                Consulta las compras y los saldos con proveedores.
            </flux:text>
        </div>
    </div>

    {{-- Contenedor principal --}}
    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">

        {{-- Filtros --}}
        <div class="grid items-end gap-4 border-b border-zinc-200 p-4 sm:grid-cols-2 xl:grid-cols-4 dark:border-zinc-700">

            <flux:input
                wire:model.live.debounce.300ms="search"
                label="Buscar"
                placeholder="Código, proveedor o documento"
                maxlength="100"
            />

            <flux:select
                wire:model.live="proveedorId"
                label="Proveedor"
            >
                <option value="">Todos</option>

                @foreach ($proveedores as $proveedor)
                    <option value="{{ $proveedor->id }}">
                        {{ $proveedor->nombre }}
                    </option>
                @endforeach
            </flux:select>

            <flux:select
                wire:model.live="stateFilter"
                label="Estado"
            >
                <option value="">Todos</option>
                <option value="borrador">Borrador</option>
                <option value="confirmada">Confirmada</option>
                <option value="anulada">Anulada</option>
            </flux:select>

            <flux:select
                wire:model.live="pagoFilter"
                label="Situación de pago"
            >
                <option value="">Todas</option>
                <option value="pendiente">Con saldo pendiente</option>
                <option value="pagada">Pagadas</option>
                <option value="vencida">Vencidas</option>
            </flux:select>

            <flux:input
                type="date"
                wire:model.live="from"
                label="Desde"
                max="{{ $hoy }}"
            />

            <flux:input
                type="date"
                wire:model.live="to"
                label="Hasta"
                max="{{ $hoy }}"
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
                        Compras confirmadas
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $resumen['confirmadas'] }}
                    </p>

                    <p class="mt-1 text-xs text-zinc-500">
                        {{ $resumen['compras'] }} registros en total
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Total confirmado
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $moneda($resumen['total_confirmado']) }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Pagado actualmente
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums">
                        {{ $moneda($resumen['pagado']) }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-zinc-500">
                        Saldo pendiente actual
                    </p>

                    <p class="mt-1 text-lg font-semibold tabular-nums text-amber-700 dark:text-amber-400">
                        {{ $moneda($resumen['pendiente']) }}
                    </p>

                    <p class="mt-1 text-xs text-zinc-500">
                        {{ $resumen['vencidas'] }} compras vencidas
                    </p>
                </div>

            </div>

            {{-- Tabla --}}
            <div class="overflow-x-auto">

                <table class="w-full text-left text-sm">

                    <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-3">
                                Compra / Proveedor
                            </th>

                            <th class="px-4 py-3">
                                Fecha / Documento
                            </th>

                            <th class="px-4 py-3">
                                Estado
                            </th>

                            <th class="px-4 py-3">
                                Condición
                            </th>

                            <th class="px-4 py-3 text-right">
                                Total
                            </th>

                            <th class="px-4 py-3 text-right">
                                Pagado
                            </th>

                            <th class="px-4 py-3 text-right">
                                Pendiente
                            </th>

                            <th class="px-4 py-3">
                                Situación de pago
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">

                        @forelse ($compras as $compra)

                            @php
                                $confirmada = $compra->estado === 'confirmada';

                                $saldo = $confirmada
                                    ? (string) $compra->pendiente
                                    : null;

                                $pagada = $confirmada
                                    && bccomp($saldo, '0', 2) === 0;

                                $vencida = $confirmada
                                    && $compra->condicion_pago === 'credito'
                                    && $compra->fecha_vencimiento
                                    && $compra->fecha_vencimiento < $hoy
                                    && bccomp($saldo, '0', 2) > 0;
                            @endphp

                            <tr wire:key="reporte-compra-{{ $compra->id }}">

                                <td class="px-4 py-3">

                                    <p class="font-medium">
                                        {{ $compra->proveedor }}
                                    </p>

                                    <p class="mt-1 max-w-64 break-all text-xs text-zinc-500">
                                        {{ $compra->codigo }}
                                    </p>
                                </td>

                                <td class="whitespace-nowrap px-4 py-3">

                                    {{ $fecha($compra->fecha) }}

                                    @if ($compra->numero)
                                        <p class="mt-1 text-xs text-zinc-500">
                                            {{ $compra->documento_nombre }}
                                            {{ $compra->serie }}-{{ $compra->numero }}
                                        </p>
                                    @endif

                                </td>

                                <td class="px-4 py-3">
                                    <x-estado
                                        :estado="$compra->estado"
                                        size="sm"
                                    >
                                        {{ ucfirst($compra->estado) }}
                                    </x-estado>
                                </td>

                                <td class="px-4 py-3">

                                    {{ $compra->condicion_pago === 'credito'
                                        ? 'Crédito'
                                        : 'Contado' }}

                                    @if ($compra->fecha_vencimiento)
                                        <p class="mt-1 whitespace-nowrap text-xs text-zinc-500">
                                            Vence: {{ $fecha($compra->fecha_vencimiento) }}
                                        </p>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                    {{ $moneda($compra->total) }}
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                    {{ $confirmada ? $moneda($compra->pagado) : '—' }}
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">
                                    {{ $moneda($saldo) }}
                                </td>

                                <td class="px-4 py-3">
                                    @if (!$confirmada)
                                        <span class="text-xs text-zinc-500">
                                            No aplica
                                        </span>
                                    @elseif ($pagada)
                                        <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                            Pagada
                                        </span>
                                    @elseif ($vencida)
                                        <span class="text-xs font-medium text-red-600 dark:text-red-400">
                                            Vencida
                                        </span>
                                    @else
                                        <span class="text-xs font-medium text-amber-700 dark:text-amber-400">
                                            Pendiente
                                        </span>
                                    @endif
                                </td>

                            </tr>

                        @empty

                            <tr>
                                <td
                                    colspan="8"
                                    class="px-4 py-10 text-center text-zinc-500"
                                >
                                    No hay compras con los filtros seleccionados.
                                </td>
                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

            <div class="border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700">
                Las fechas corresponden a los documentos de compra.
                Los pagos y saldos son actuales.
                Los borradores y las compras anuladas no se incluyen en los totales financieros.
            </div>

            {{-- Paginación --}}
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $compras->links() }}
            </div>

        @endif

    </div>

</div>
