<div class="mx-auto w-full max-w-7xl space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4"><div><p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">INVENTARIO</p><h1 class="text-2xl font-semibold tracking-tight">Preparación diaria</h1><p class="mt-1 text-sm text-zinc-500">Registra la producción real y conserva el historial de consumos.</p></div>@can('preparaciones.crear')<flux:button href="{{ route('preparaciones.crear') }}" wire:navigate variant="primary" icon="plus">Nueva preparación</flux:button>@endcan</header>
    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid items-end gap-3 border-b border-zinc-200 p-4 sm:grid-cols-2 xl:grid-cols-[minmax(12rem,1fr)_10rem_10rem_10rem_auto] dark:border-zinc-700">
            <flux:input wire:model.live.debounce.300ms="search" label="Buscar preparación" type="search" icon="magnifying-glass" placeholder="Código o producto" maxlength="100" />
            <flux:select wire:model.live="stateFilter" label="Estado"><option value="">Todos</option><option value="borrador">Borrador</option><option value="confirmada">Confirmada</option><option value="anulada">Anulada</option></flux:select>
            <flux:input wire:model.live="from" label="Desde" type="date" /><flux:input wire:model.live="to" label="Hasta" type="date" />
            <flux:button wire:click="clearFilters" variant="ghost">Limpiar</flux:button>
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,stateFilter,from,to,gotoPage,nextPage,previousPage">
            <table class="w-full text-left text-sm"><caption class="sr-only">Historial de preparaciones</caption><thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60"><tr><th class="px-4 py-3 font-medium">Preparación</th><th class="px-4 py-3 font-medium">Fecha</th><th class="px-4 py-3 font-medium">Cantidad</th><th class="px-4 py-3 font-medium">Registrado por</th><th class="px-4 py-3 font-medium">Estado</th><th class="px-4 py-3 text-right font-medium">Detalle</th></tr></thead><tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($preparaciones as $preparacion)
                    <tr wire:key="preparacion-{{ $preparacion->id }}"><td class="px-4 py-3"><p class="font-medium">{{ $preparacion->producto_nombre ?? $preparacion->producto->nombre }}</p><p class="mt-0.5 text-xs text-zinc-500">{{ $preparacion->codigo }}</p></td><td class="whitespace-nowrap px-4 py-3 text-zinc-500">{{ $preparacion->fecha->format('d/m/Y') }}</td><td class="px-4 py-3">{{ \App\Support\Cantidad::mostrar($preparacion->cantidad_producida) }} {{ $preparacion->unidad_codigo }}</td><td class="px-4 py-3 text-zinc-500">{{ $preparacion->usuario->name }}</td><td class="px-4 py-3"><x-estado :estado="$preparacion->estado->value" size="sm">{{ ucfirst($preparacion->estado->value) }}</x-estado></td><td class="px-4 py-3 text-right"><flux:button size="sm" variant="ghost" icon="eye" href="{{ route('preparaciones.ver', $preparacion) }}" wire:navigate aria-label="Ver preparación {{ $preparacion->codigo }}" title="Ver detalle" /></td></tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-zinc-500">No se encontraron preparaciones. Registra una o ajusta los filtros.</td></tr>
                @endforelse
            </tbody></table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700"><span>{{ $preparaciones->total() }} resultados</span>{{ $preparaciones->links() }}</div>
    </section>
</div>
