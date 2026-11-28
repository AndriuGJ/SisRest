<div class="mx-auto w-full max-w-7xl space-y-5">

    <header>
        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">
            Configuración del restaurante
        </p>
        <h1 class="text-2xl font-semibold">
            Seguridad de anulaciones
        </h1>
        <p class="mt-1 text-sm text-zinc-500">
            Administra la clave de autorización y los motivos de anulación.
        </p>
    </header>

    @can('configuracion.sistema')
        <section class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">

            <div class="mb-5 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">Clave de autorización</h2>
                    <p class="mt-1 text-xs text-zinc-500">
                        Utilizada para autorizar anulaciones en el POS.
                    </p>
                </div>

                <x-estado
                    :estado="$claveConfigurada ? 'activo' : 'inactivo'"
                    size="sm"
                >
                    {{ $claveConfigurada ? 'Configurada' : 'Sin configurar' }}
                </x-estado>
            </div>

            <form wire:submit="guardarClave" autocomplete="off" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-3">

                    @if ($claveConfigurada)
                        <flux:input
                            type="password"
                            wire:model="claveActual"
                            label="Clave actual"
                            autocomplete="off"
                            required
                        />
                    @endif

                    <flux:input
                        type="password"
                        wire:model="claveNueva"
                        label="Nueva clave"
                        placeholder="6 a 12 dígitos"
                        autocomplete="new-password"
                        maxlength="12"
                        required
                    />

                    <flux:input
                        type="password"
                        wire:model="claveConfirmacion"
                        label="Confirmar nueva clave"
                        autocomplete="new-password"
                        maxlength="12"
                        required
                    />

                </div>

                <flux:error name="claveAutorizacion" />

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-zinc-500">
                        La clave no se mostrará después de guardarla.
                        No compartas la clave con personal no autorizado.
                    </p>

                    <flux:button
                        type="submit"
                        variant="primary"
                        icon="shield-check"
                        wire:loading.attr="disabled"
                        wire:target="guardarClave"
                    >
                        {{ $claveConfigurada ? 'Actualizar clave' : 'Configurar clave' }}
                    </flux:button>
                </div>
            </form>
        </section>
    @endcan

    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700">
            <div>
                <h2 class="text-base font-semibold">Motivos de anulación</h2>
                <p class="mt-1 text-xs text-zinc-500">
                    Opciones que se mostrarán al anular un producto o pedido.
                </p>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="nuevoMotivo">
                Nuevo motivo
            </flux:button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <tr>
                        <th class="px-4 py-3">Motivo</th>
                        <th class="px-4 py-3">Orden</th>
                        <th class="px-4 py-3">Estado</th>
                        <th class="px-4 py-3 text-right">Acciones</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($motivos as $motivo)
                        <tr wire:key="motivo-anulacion-{{ $motivo->id }}">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $motivo->nombre }}</p>
                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $motivo->codigo }}
                                </p>
                            </td>

                            <td class="px-4 py-3">{{ $motivo->orden }}</td>

                            <td class="px-4 py-3">
                                <x-estado
                                    :estado="$motivo->activo ? 'activo' : 'inactivo'"
                                    size="sm"
                                >
                                    {{ $motivo->activo ? 'Activo' : 'Inactivo' }}
                                </x-estado>
                            </td>

                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-1">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="pencil-square"
                                        wire:click="editarMotivo({{ $motivo->id }})"
                                        aria-label="Editar motivo"
                                    />

                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        :icon="$motivo->activo ? 'no-symbol' : 'check-circle'"
                                        wire:click="solicitarCambioEstado({{ $motivo->id }})"
                                        :aria-label="$motivo->activo ? 'Desactivar motivo' : 'Activar motivo'"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-zinc-500">
                                No hay motivos registrados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <flux:modal
        wire:model.self="showMotivoForm"
        name="motivo-anulacion-formulario"
        class="w-full md:min-w-[32rem]"
        :dismissible="false"
    >
        <form wire:submit="guardarMotivo" class="space-y-4">

            <flux:heading size="lg">
                {{ $motivoId ? 'Editar motivo' : 'Nuevo motivo' }}
            </flux:heading>

            <flux:input
                wire:model="nombre"
                label="Nombre del motivo"
                placeholder="Ej. Error en el pedido"
                maxlength="100"
                required
            />

            <flux:input
                wire:model="codigo"
                label="Código"
                placeholder="Ej. error_pedido"
                maxlength="40"
                :disabled="$motivoId !== null"
                required
            />

            <flux:input
                wire:model="orden"
                type="number"
                min="0"
                max="65535"
                label="Orden de visualización"
                required
            />

            <flux:checkbox
                wire:model="activo"
                label="Motivo activo"
            />

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button
                    type="button"
                    wire:click="$set('showMotivoForm', false)"
                >
                    Cancelar
                </flux:button>

                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="guardarMotivo"
                >
                    Guardar motivo
                </flux:button>
            </div>
        </form>
    </flux:modal>

</div>
