<div class="mx-auto w-full max-w-7xl space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">CONFIGURACIÓN DEL RESTAURANTE</p>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Salones</h1>
            <p class="mt-1 text-sm text-zinc-500">Organiza los ambientes donde atiendes a tus clientes.</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="newRecord">Nuevo salón</flux:button>
    </header>

    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid items-end gap-3 border-b border-zinc-200 p-4 sm:grid-cols-[1fr_11rem_auto] dark:border-zinc-700">
            <flux:input wire:model.live.debounce.300ms="search" label="Buscar salón" type="search" icon="magnifying-glass" placeholder="Nombre o código" maxlength="100" />
            <flux:select wire:model.live="stateFilter" label="Estado">
                <option value="">Todos los estados</option>
                <option value="activo">Activos</option>
                <option value="inactivo">Inactivos</option>
            </flux:select>
            <flux:button wire:click="clearFilters" variant="ghost">Limpiar</flux:button>
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,stateFilter,gotoPage,nextPage,previousPage">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Salones del restaurante</caption>
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <tr><th class="px-4 py-3 font-medium">Salón</th><th class="px-4 py-3 font-medium">Mesas</th><th class="px-4 py-3 font-medium">Orden</th><th class="px-4 py-3 font-medium">Estado</th><th class="px-4 py-3 text-right font-medium">Acciones</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($salones as $salon)
                        <tr wire:key="salon-{{ $salon->id }}">
                            <td class="px-4 py-3"><p class="font-medium text-zinc-900 dark:text-white">{{ $salon->nombre }}</p><p class="mt-0.5 text-xs text-zinc-500">{{ $salon->codigo }}</p></td>
                            <td class="px-4 py-3"><span>{{ $salon->mesas_count }} en total</span><p class="mt-0.5 text-xs text-zinc-500">{{ $salon->mesas_activas_count }} {{ $salon->mesas_activas_count === 1 ? 'activa' : 'activas' }}</p></td>
                            <td class="px-4 py-3 text-zinc-500">{{ $salon->orden }}</td>
                            <td class="px-4 py-3"><flux:badge size="sm" :color="$salon->activo ? 'green' : 'zinc'">{{ $salon->activo ? 'Activo' : 'Inactivo' }}</flux:badge></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <flux:button size="sm" variant="ghost" icon="squares-2x2" href="{{ route('configuracion.mesas', ['salon' => $salon->id]) }}" wire:navigate aria-label="Ver mesas de {{ $salon->nombre }}" title="Ver mesas" />
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editRecord({{ $salon->id }})" aria-label="Editar salón {{ $salon->nombre }}" title="Editar" />
                                <flux:button size="sm" variant="ghost" :icon="$salon->activo ? 'no-symbol' : 'check-circle'" wire:click="requestStateChange({{ $salon->id }})" aria-label="{{ $salon->activo ? 'Desactivar' : 'Activar' }} salón {{ $salon->nombre }}" :title="$salon->activo ? 'Desactivar' : 'Activar'" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-zinc-500">No se encontraron salones. Registra uno o ajusta los filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700"><span>{{ $salones->total() }} resultados</span>{{ $salones->links() }}</div>
    </section>
    <p class="text-xs text-zinc-500">Al desactivar un salón, sus mesas no estarán disponibles para nuevas atenciones. Se conserva la configuración individual de cada mesa.</p>

    <flux:modal wire:model.self="showForm" name="salon-formulario" class="w-full md:min-w-[30rem]" :dismissible="false">
        <form wire:submit="save" class="space-y-5">
            <div class="pr-6"><flux:heading size="lg">{{ $salonId ? 'Editar salón' : 'Nuevo salón' }}</flux:heading><flux:text class="mt-1">Define el nombre y el orden en que aparecerá en el POS.</flux:text></div>
            <flux:error name="seguridad" />
            <flux:input wire:model="nombre" label="Nombre del salón" placeholder="Ej. Terraza" maxlength="100" required />
            <div class="grid items-start gap-4 sm:grid-cols-2">
                <flux:input wire:model="codigo" label="Código" placeholder="Ej. terraza" maxlength="30" description:trailing="Único; letras sin tildes, números o guiones." required />
                <flux:input wire:model="orden" label="Orden de visualización" type="number" min="0" max="65535" step="1" description:trailing="Los números menores aparecen primero." required />
            </div>
            <flux:checkbox wire:model="activo" label="Salón activo" description:trailing="Habilita sus mesas activas para atender." />
            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Guardar salón</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
