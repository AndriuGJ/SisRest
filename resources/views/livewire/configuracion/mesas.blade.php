<div class="mx-auto w-full max-w-7xl space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">CONFIGURACIÓN DEL RESTAURANTE</p>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Mesas</h1>
            <p class="mt-1 text-sm text-zinc-500">Configura las mesas y su capacidad por salón.</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="newRecord" :disabled="$salones->isEmpty()">Nueva mesa</flux:button>
    </header>
    @if($salones->isEmpty())
        <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">Primero registra un salón desde Configuración del restaurante en el sidebar.</p>
    @endif
    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid items-end gap-3 border-b border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(12rem,1fr)_12rem_11rem_auto] dark:border-zinc-700">
            <flux:input wire:model.live.debounce.300ms="search" label="Buscar mesa" type="search" icon="magnifying-glass" placeholder="Número o nombre de mesa" maxlength="100" />
            <flux:select wire:model.live="salonFilter" label="Salón">
                <option value="">Todos los salones</option>
                @foreach($salones as $salon)<option value="{{ $salon->id }}">{{ $salon->nombre }}{{ $salon->activo ? '' : ' (inactivo)' }}</option>@endforeach
            </flux:select>
            <flux:select wire:model.live="stateFilter" label="Estado">
                <option value="">Todos los estados</option><option value="activo">Activas</option><option value="inactivo">Inactivas</option>
            </flux:select>
            <flux:button wire:click="clearFilters" variant="ghost">Limpiar</flux:button>
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,salonFilter,stateFilter,gotoPage,nextPage,previousPage">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Mesas del restaurante</caption>
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <tr><th class="px-4 py-3 font-medium">Mesa</th><th class="px-4 py-3 font-medium">Salón</th><th class="px-4 py-3 font-medium">Capacidad</th><th class="px-4 py-3 font-medium">Estado</th><th class="px-4 py-3 text-right font-medium">Acciones</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($mesas as $mesa)
                        @php($enUso = $mesa->ocupacion_exists || $mesa->pedido_pendiente_exists)
                        <tr wire:key="mesa-{{ $mesa->id }}">
                            <td class="px-4 py-3"><p class="font-medium text-zinc-900 dark:text-white">{{ $mesa->codigo }}</p>@if($enUso)<p class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">En uso</p>@endif</td>
                            <td class="px-4 py-3">{{ $mesa->salon->nombre }}@unless($mesa->salon->activo)<p class="mt-0.5 text-xs text-zinc-500">Salón inactivo</p>@endunless</td>
                            <td class="px-4 py-3 text-zinc-500">{{ $mesa->capacidad ? $mesa->capacidad.($mesa->capacidad === 1 ? ' persona' : ' personas') : 'Sin definir' }}</td>
                            <td class="px-4 py-3"><x-estado :estado="$mesa->activo ? 'activo' : 'inactivo'" size="sm">{{ $mesa->activo ? 'Activa' : 'Inactiva' }}</x-estado></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editRecord({{ $mesa->id }})" aria-label="Editar mesa {{ $mesa->codigo }} de {{ $mesa->salon->nombre }}" :disabled="$enUso" :title="$enUso ? 'Libera la mesa desde el POS antes de editarla' : 'Editar'" />
                                <flux:button size="sm" variant="ghost" :icon="$mesa->activo ? 'no-symbol' : 'check-circle'" wire:click="requestStateChange({{ $mesa->id }})" aria-label="{{ $mesa->activo ? 'Desactivar' : 'Activar' }} mesa {{ $mesa->codigo }} de {{ $mesa->salon->nombre }}" :title="$mesa->activo ? 'Desactivar' : 'Activar'" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-zinc-500">No se encontraron mesas. Registra una o ajusta los filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700"><span>{{ $mesas->total() }} resultados</span>{{ $mesas->links() }}</div>
    </section>
    <p class="text-xs text-zinc-500">El número o nombre es único dentro de cada salón. Las mesas en uso se cierran y liberan desde el POS.</p>

    <flux:modal wire:model.self="showForm" name="mesa-formulario" class="w-full md:min-w-[30rem]" :dismissible="false">
        <form wire:submit="save" class="space-y-5">
            <div class="pr-6"><flux:heading size="lg">{{ $mesaId ? 'Editar mesa' : 'Nueva mesa' }}</flux:heading><flux:text class="mt-1">Asigna la mesa a su salón e indica cuántas personas pueden sentarse.</flux:text></div>
            <flux:error name="seguridad" />
            <flux:select wire:model="salonId" label="Salón de la mesa" required>
                <option value="">Seleccionar salón</option>
                @foreach($salones as $salon)<option value="{{ $salon->id }}">{{ $salon->nombre }}{{ $salon->activo ? '' : ' (inactivo)' }}</option>@endforeach
            </flux:select>
            <div class="grid items-start gap-4 sm:grid-cols-2">
                <flux:input wire:model="codigo" label="Número o nombre de mesa" placeholder="Ej. 01 o VIP 1" maxlength="30" required />
                <flux:input wire:model="capacidad" label="Capacidad" badge="Opcional" type="number" min="1" max="65535" step="1" placeholder="Ej. 4" description:trailing="Cantidad de personas." />
            </div>
            <flux:checkbox wire:model="activo" label="Mesa activa" description:trailing="El salón también debe estar activo para atender." />
            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Guardar mesa</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
