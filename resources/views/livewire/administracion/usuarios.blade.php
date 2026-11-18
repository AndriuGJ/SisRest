<div class="mx-auto w-full max-w-7xl space-y-5">
    <x-administracion.tabs active="usuarios" />

    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">ADMINISTRACIÓN</p>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Usuarios</h1>
            <p class="mt-1 text-sm text-zinc-500">Administra las cuentas y el acceso de tu equipo.</p>
        </div>
        <flux:button variant="primary" icon="user-plus" wire:click="newUser">Nuevo usuario</flux:button>
    </header>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-zinc-500">
        <span><strong class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $totalUsuarios }}</strong> usuarios</span>
        <span><span class="mr-1.5 inline-block size-1.5 rounded-full bg-emerald-600"></span>{{ $activos }} activos</span>
        <span>{{ $totalUsuarios - $activos }} inactivos</span>
    </div>

    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid items-end gap-3 border-b border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(12rem,1fr)_11rem_10rem_auto] dark:border-zinc-700">
            <flux:input wire:model.live.debounce.300ms="search" label="Buscar" type="search" icon="magnifying-glass"
                placeholder="Nombre, usuario o correo" maxlength="100" />
            <flux:select wire:model.live="roleFilter" label="Rol">
                <option value="">Todos los roles</option>
                @foreach($roles as $rol)
                    <option value="{{ $rol->id }}">{{ str($rol->name)->headline() }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="stateFilter" label="Estado">
                <option value="">Todos los estados</option>
                <option value="activo">Activos</option>
                <option value="inactivo">Inactivos</option>
            </flux:select>
            <flux:button variant="ghost" wire:click="clearFilters" class="justify-self-start">Limpiar filtros</flux:button>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,roleFilter,stateFilter,gotoPage,nextPage,previousPage">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Usuarios del restaurante</caption>
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-medium text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Nombre</th>
                        <th scope="col" class="px-4 py-3 font-medium">Usuario</th>
                        <th scope="col" class="px-4 py-3 font-medium">Rol</th>
                        <th scope="col" class="px-4 py-3 font-medium">Estado</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($usuarios as $usuario)
                        <tr wire:key="usuario-{{ $usuario->id }}" class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-800/50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" aria-hidden="true">{{ $usuario->initials() }}</div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $usuario->name }}</span>
                                            @if($usuario->id === auth()->id())
                                                <span class="text-xs text-zinc-400">Tú</span>
                                            @endif
                                        </div>
                                        <span class="text-xs text-zinc-500">{{ $usuario->email ?: 'Sin correo registrado' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $usuario->username }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @forelse($usuario->roles as $rol)
                                        <span class="rounded-md bg-zinc-100 px-2 py-1 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ str($rol->name)->headline() }}</span>
                                    @empty
                                        <span class="text-xs text-zinc-400">Sin rol asignado</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium',
                                    'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300' => $usuario->activo,
                                    'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300' => ! $usuario->activo,
                                ])>{{ $usuario->activo ? 'Activo' : 'Inactivo' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editUser({{ $usuario->id }})"
                                        aria-label="Editar a {{ $usuario->name }}" title="Editar usuario" />
                                    <flux:button size="sm" variant="ghost" icon="key" wire:click="requestPasswordReset({{ $usuario->id }})"
                                        aria-label="Restablecer contraseña de {{ $usuario->name }}" title="Restablecer contraseña" />
                                    @if($usuario->id !== auth()->id())
                                        <flux:button size="sm" variant="ghost" :icon="$usuario->activo ? 'no-symbol' : 'check-circle'"
                                            wire:click="requestStateChange({{ $usuario->id }})"
                                            :aria-label="($usuario->activo ? 'Desactivar a ' : 'Activar a ').$usuario->name"
                                            :title="$usuario->activo ? 'Desactivar usuario' : 'Activar usuario'" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-14 text-center">
                            <flux:icon.users class="mx-auto mb-3 size-8 text-zinc-300 dark:text-zinc-600" />
                            <p class="font-medium text-zinc-700 dark:text-zinc-200">No se encontraron usuarios</p>
                            <p class="mt-1 text-sm text-zinc-500">Prueba otros filtros o registra una nueva cuenta.</p>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
            @if($usuarios->hasPages()) {{ $usuarios->links() }} @else <span class="text-xs text-zinc-500">{{ $usuarios->total() }} {{ $usuarios->total() === 1 ? 'resultado' : 'resultados' }}</span> @endif
        </div>
    </section>

    <flux:modal wire:model.self="showForm" name="usuario-formulario" class="w-full md:min-w-[34rem]" :dismissible="false">
        <form wire:submit="save" class="space-y-5">
            <div class="pr-6">
                <flux:heading size="lg">{{ $userId ? 'Editar usuario' : 'Nuevo usuario' }}</flux:heading>
                <flux:text class="mt-1">Define sus datos y el rol que utilizará en el restaurante.</flux:text>
            </div>
            <flux:error name="seguridad" />
            <flux:input wire:model="name" label="Nombre completo" maxlength="150" required autocomplete="name" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="username" label="Usuario" maxlength="50" required autocomplete="off" placeholder="Ej. mozo01" />
                <flux:select wire:model="roleId" label="Rol" required :disabled="$userId === auth()->id()">
                    <option value="">Seleccionar rol</option>
                    @foreach($roles as $rol)
                        <option value="{{ $rol->id }}">{{ str($rol->name)->headline() }}</option>
                    @endforeach
                </flux:select>
            </div>
            @if($userId === auth()->id())
                <p class="text-xs text-zinc-500">Otro administrador puede cambiar tu rol o desactivar tu cuenta.</p>
            @endif
            <flux:input wire:model="email" label="Correo electrónico" badge="Opcional" type="email" maxlength="150" autocomplete="off" />
            @unless($userId)
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="password" label="Contraseña inicial" type="password" viewable required autocomplete="new-password" description="Mínimo 12 caracteres." />
                    <flux:input wire:model="password_confirmation" label="Confirmar contraseña" type="password" viewable required autocomplete="new-password" />
                </div>
            @endunless
            <flux:checkbox wire:model="activo" label="Usuario activo" description="Puede iniciar sesión y utilizar sus funciones asignadas." :disabled="$userId === auth()->id()" />
            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Guardar usuario</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showPassword" name="usuario-password" class="w-full md:min-w-[28rem]" :dismissible="false">
        <form wire:submit="resetPassword" class="space-y-5">
            <div class="pr-6">
                <flux:heading size="lg">Restablecer contraseña</flux:heading>
                <flux:text class="mt-1">Nueva contraseña para <strong>{{ $targetName }}</strong>. Sus sesiones anteriores se cerrarán.</flux:text>
            </div>
            <flux:input wire:model="newPassword" label="Nueva contraseña" type="password" viewable required autocomplete="new-password" description="Mínimo 12 caracteres." />
            <flux:input wire:model="newPassword_confirmation" label="Confirmar nueva contraseña" type="password" viewable required autocomplete="new-password" />
            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button type="button" wire:click="$set('showPassword', false)">Cancelar</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="resetPassword">Actualizar contraseña</flux:button>
            </div>
        </form>
    </flux:modal>

</div>
