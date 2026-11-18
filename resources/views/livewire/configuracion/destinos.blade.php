<div class="mx-auto w-full max-w-7xl space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">CONFIGURACIÓN DEL RESTAURANTE</p>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Destinos de preparación</h1>
            <p class="mt-1 text-sm text-zinc-500">Define dónde se preparan los productos: Cocina, Barra u otros destinos.</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="newRecord">Nuevo destino</flux:button>
    </header>
    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid items-end gap-3 border-b border-zinc-200 p-4 sm:grid-cols-[1fr_11rem_auto] dark:border-zinc-700">
            <flux:input wire:model.live.debounce.300ms="search" label="Buscar destino" type="search" icon="magnifying-glass" placeholder="Nombre o código" maxlength="100" />
            <flux:select wire:model.live="stateFilter" label="Estado"><option value="">Todos los estados</option><option value="activo">Activos</option><option value="inactivo">Inactivos</option></flux:select>
            <flux:button wire:click="clearFilters" variant="ghost">Limpiar</flux:button>
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,stateFilter,gotoPage,nextPage,previousPage">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Destinos de preparación del restaurante</caption>
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60"><tr><th class="px-4 py-3 font-medium">Destino</th><th class="px-4 py-3 font-medium">Productos</th><th class="px-4 py-3 font-medium">Impresoras</th><th class="px-4 py-3 font-medium">Estado</th><th class="px-4 py-3 text-right font-medium">Acciones</th></tr></thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($destinos as $destino)
                        <tr wire:key="destino-{{ $destino->id }}">
                            <td class="px-4 py-3"><p class="font-medium text-zinc-900 dark:text-white">{{ $destino->nombre }}</p><p class="mt-0.5 text-xs text-zinc-500">{{ $destino->codigo }}</p></td>
                            <td class="px-4 py-3 text-zinc-500">{{ $destino->productos_count }}</td>
                            <td class="px-4 py-3 text-zinc-500">{{ $destino->impresoras_count }}</td>
                            <td class="px-4 py-3"><flux:badge size="sm" :color="$destino->activo ? 'green' : 'zinc'">{{ $destino->activo ? 'Activo' : 'Inactivo' }}</flux:badge></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editRecord({{ $destino->id }})" aria-label="Editar destino {{ $destino->nombre }}" title="Editar" />
                                <flux:button size="sm" variant="ghost" :icon="$destino->activo ? 'no-symbol' : 'check-circle'" wire:click="requestStateChange({{ $destino->id }})" aria-label="{{ $destino->activo ? 'Desactivar' : 'Activar' }} destino {{ $destino->nombre }}" :title="$destino->activo ? 'Desactivar' : 'Activar'" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-zinc-500">No se encontraron destinos. Registra uno o ajusta los filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700"><span>{{ $destinos->total() }} resultados</span>{{ $destinos->links() }}</div>
    </section>
    <p class="text-xs text-zinc-500">Las impresoras se asignan desde Impresoras. Para desactivar un destino no debe tener productos activos ni preparaciones o entregas pendientes.</p>
    <flux:modal wire:model.self="showForm" name="destino-formulario" class="w-full md:min-w-[30rem]" :dismissible="false">
        <form wire:submit="save" class="space-y-5">
            <div class="pr-6"><flux:heading size="lg">{{ $destinoId ? 'Editar destino' : 'Nuevo destino' }}</flux:heading><flux:text class="mt-1">Los productos se enviarán al destino que tengan asignado.</flux:text></div>
            <flux:error name="seguridad" />
            <flux:input wire:model="nombre" label="Nombre del destino" placeholder="Ej. Parrilla" maxlength="100" required />
            <flux:input wire:model="codigo" label="Código" placeholder="Ej. parrilla" maxlength="30" :readonly="$destinoId !== null" description:trailing="Letras sin tildes, números o guiones. Se conserva después de crear." required />
            <flux:checkbox wire:model="activo" label="Destino activo" description="Disponible para asignar productos y nuevas preparaciones." />
            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700"><flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Guardar destino</flux:button></div>
        </form>
    </flux:modal>
</div>
