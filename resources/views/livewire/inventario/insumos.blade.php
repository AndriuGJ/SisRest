<div class="mx-auto w-full max-w-7xl space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4"><div><p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">INVENTARIO</p><h1 class="text-2xl font-semibold tracking-tight">Insumos</h1><p class="mt-1 text-sm text-zinc-500">Materia prima, unidades y presentaciones para tus preparaciones.</p></div>@can('insumos.crear')<flux:button href="{{ route('insumos.crear') }}" wire:navigate variant="primary" icon="plus">Nuevo insumo</flux:button>@endcan</header>
    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid items-end gap-3 border-b border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(12rem,1fr)_11rem_11rem_auto] dark:border-zinc-700">
            <flux:input wire:model.live.debounce.300ms="search" label="Buscar insumo" type="search" icon="magnifying-glass" placeholder="Nombre o código" maxlength="100" />
            <flux:select wire:model.live="stateFilter" label="Estado"><option value="">Todos los estados</option><option value="activo">Activos</option><option value="inactivo">Inactivos</option></flux:select>
            <flux:select wire:model.live="stockFilter" label="Existencias"><option value="">Todas</option><option value="bajo">Bajo el mínimo</option></flux:select>
            <flux:button wire:click="clearFilters" variant="ghost">Limpiar</flux:button>
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,stateFilter,stockFilter,gotoPage,nextPage,previousPage">
            <table class="w-full text-left text-sm"><caption class="sr-only">Insumos del restaurante</caption><thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60"><tr><th class="px-4 py-3 font-medium">Insumo</th><th class="px-4 py-3 font-medium">Unidad</th><th class="px-4 py-3 font-medium">Disponible</th><th class="px-4 py-3 font-medium">Mínimo</th><th class="px-4 py-3 font-medium">Estado</th><th class="px-4 py-3 text-right font-medium">Acciones</th></tr></thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($insumos as $insumo)
                        <tr wire:key="insumo-{{ $insumo->id }}"><td class="px-4 py-3"><p class="font-medium">{{ $insumo->nombre }}</p><p class="mt-0.5 text-xs text-zinc-500">{{ $insumo->codigo }}</p></td><td class="px-4 py-3 text-zinc-500">{{ $insumo->unidadMedida->codigo }}</td><td class="px-4 py-3"><span>{{ \App\Support\Cantidad::mostrar($insumo->disponible) }}</span>@if(bccomp($insumo->stock_minimo, '0', 4) > 0 && bccomp($insumo->disponible, $insumo->stock_minimo, 4) <= 0)<p class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">Bajo el mínimo</p>@endif</td><td class="px-4 py-3 text-zinc-500">{{ \App\Support\Cantidad::mostrar($insumo->stock_minimo) }}</td><td class="px-4 py-3"><flux:badge size="sm" :color="$insumo->activo ? 'green' : 'zinc'">{{ $insumo->activo ? 'Activo' : 'Inactivo' }}</flux:badge></td><td class="whitespace-nowrap px-4 py-3 text-right">@can('insumos.editar')<flux:button size="sm" variant="ghost" icon="pencil-square" href="{{ route('insumos.editar', $insumo) }}" wire:navigate aria-label="Editar insumo {{ $insumo->nombre }}" title="Editar" /><flux:button size="sm" variant="ghost" :icon="$insumo->activo ? 'no-symbol' : 'check-circle'" wire:click="requestStateChange({{ $insumo->id }})" aria-label="{{ $insumo->activo ? 'Desactivar' : 'Activar' }} insumo {{ $insumo->nombre }}" :title="$insumo->activo ? 'Desactivar' : 'Activar'" />@endcan</td></tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-zinc-500">No se encontraron insumos. Registra uno o ajusta los filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700"><span>{{ $insumos->total() }} resultados</span>{{ $insumos->links() }}</div>
    </section>
    <p class="text-xs text-zinc-500">Las cantidades se expresan en la unidad de inventario. Guardar o desactivar un insumo conserva sus existencias.</p>
</div>
