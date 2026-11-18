<div class="mx-auto w-full max-w-7xl space-y-5">
    <x-administracion.tabs active="roles" />
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">ADMINISTRACIÓN</p>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Roles y permisos</h1>
            <p class="mt-1 text-sm text-zinc-500">Define qué puede hacer cada integrante del equipo.</p>
        </div>
        <flux:button href="{{ route('roles.crear') }}" wire:navigate variant="primary" icon="plus">Nuevo rol</flux:button>
    </header>

    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
            <flux:input wire:model.live.debounce.300ms="search" label="Buscar rol" type="search" icon="magnifying-glass" placeholder="Nombre del rol" maxlength="100" class="max-w-md" />
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,gotoPage,nextPage,previousPage">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Roles del restaurante</caption>
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Rol</th>
                        <th scope="col" class="px-4 py-3 font-medium">Usuarios asignados</th>
                        <th scope="col" class="px-4 py-3 font-medium">Permisos</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($roles as $rol)
                        <tr wire:key="rol-{{ $rol->id }}" class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/50">
                            <td class="px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-9 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800">
                                        <flux:icon.shield-check class="size-5" />
                                    </div>
                                    <div>
                                        <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ str($rol->name)->headline() }}</p>
                                        <p class="mt-0.5 text-xs text-zinc-500">{{ in_array($rol->name, $rolesSistema, true) ? 'Rol del sistema' : 'Rol personalizado' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-4 text-zinc-600 dark:text-zinc-300">{{ $rol->users_count }}</td>
                            <td class="px-4 py-4 text-zinc-600 dark:text-zinc-300">{{ $rol->permissions_count }} {{ $rol->permissions_count === 1 ? 'permiso' : 'permisos' }}</td>
                            <td class="px-4 py-4 text-right">
                                <flux:button href="{{ route('roles.editar', $rol) }}" wire:navigate size="sm" icon="adjustments-horizontal"
                                    aria-label="Configurar permisos de {{ str($rol->name)->headline() }}">Configurar</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-14 text-center">
                            <p class="font-medium text-zinc-700 dark:text-zinc-200">No se encontraron roles</p>
                            <p class="mt-1 text-zinc-500">Prueba otro nombre o registra un nuevo rol.</p>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">@if($roles->hasPages()) {{ $roles->links() }} @else <span class="text-xs text-zinc-500">{{ $roles->total() }} {{ $roles->total() === 1 ? 'rol' : 'roles' }}</span> @endif</div>
    </section>
</div>
