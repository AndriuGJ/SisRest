<div class="mx-auto w-full max-w-7xl space-y-5">
    <x-administracion.tabs active="roles" />
    <form wire:submit="save" class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('roles.index') }}" wire:navigate class="mb-2 inline-flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
                    <flux:icon.arrow-left class="size-3.5" /> Volver a roles
                </a>
                <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $roleId ? 'Configurar rol' : 'Nuevo rol' }}</h1>
                <p class="mt-1 text-sm text-zinc-500">Selecciona las acciones disponibles para quienes tengan este rol.</p>
            </div>
            <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">Guardar rol</flux:button>
        </header>

        <flux:error name="seguridad" />
        <flux:error name="permissions" />
        <flux:error name="permissions.*" />

        <section class="grid gap-5 rounded-lg border border-zinc-200 bg-white p-4 md:grid-cols-[minmax(16rem,24rem)_1fr] dark:border-zinc-700 dark:bg-zinc-900">
            <flux:input wire:model="name" label="Nombre del rol" placeholder="Ej. supervisor" maxlength="50" required :readonly="$systemRole"
                :description="$systemRole ? 'El nombre de este rol del sistema se conserva.' : 'Letras, números, guion o guion bajo; sin espacios.'" />
            <div class="flex items-center gap-3 md:justify-end">
                <div class="flex size-10 items-center justify-center rounded-lg bg-sky-50 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300">
                    <flux:icon.shield-check class="size-5" />
                </div>
                <div>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ count($permissions) }} de {{ $totalPermisos }} permisos</p>
                    <p class="text-xs text-zinc-500">Los cambios se aplican a los usuarios asignados.</p>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-end justify-between gap-3">
            <flux:input wire:model.live.debounce.250ms="permissionSearch" label="Buscar permisos" type="search" icon="magnifying-glass" placeholder="Módulo o acción" maxlength="100" class="w-full sm:max-w-sm" />
            <div class="flex gap-1">
                <flux:button type="button" size="sm" variant="ghost" wire:click="selectAll">Seleccionar todos</flux:button>
                <flux:button type="button" size="sm" variant="ghost" wire:click="clearSelection">Quitar selección</flux:button>
            </div>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-2">
            @forelse($grupos as $clave => $grupo)
                <section wire:key="grupo-{{ $clave }}" class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-100 bg-zinc-50/70 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/50">
                        <h2 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ $grupo['titulo'] }}</h2>
                        <flux:button type="button" size="xs" variant="ghost" wire:click="toggleGroup('{{ $clave }}')"
                            aria-label="Seleccionar o quitar módulo {{ $grupo['titulo'] }}">Seleccionar módulo</flux:button>
                    </div>
                    <div class="grid gap-x-4 gap-y-3.5 p-4 sm:grid-cols-2">
                        @foreach($grupo['acciones'] as $codigo => $etiqueta)
                            <flux:checkbox wire:key="permiso-{{ $codigo }}" wire:model.live="permissions" value="{{ $codigo }}"
                                :label="$etiqueta" :disabled="!$gestionCompleta && !auth()->user()->can($codigo)" />
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="col-span-full rounded-lg border border-dashed border-zinc-300 px-4 py-10 text-center text-sm text-zinc-500">No se encontraron permisos con esa búsqueda.</div>
            @endforelse
        </div>

        <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
            <flux:button href="{{ route('roles.index') }}" wire:navigate>Cancelar</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Guardar cambios</flux:button>
        </div>
    </form>
</div>
