<div class="mx-auto w-full max-w-7xl space-y-5">

    <header class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">
                Configuración del restaurante
            </p>
            <h1 class="text-2xl font-semibold">Medios de pago</h1>
            <p class="mt-1 text-sm text-zinc-500">
                Administra las formas de pago disponibles en ventas y compras.
            </p>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="newRecord">
            Nuevo medio
        </flux:button>
    </header>

    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">

        <div class="grid gap-3 border-b border-zinc-200 p-4 sm:grid-cols-[1fr_12rem_auto] dark:border-zinc-700">
            <flux:input
                wire:model.live.debounce.300ms="search"
                label="Buscar medio"
                placeholder="Nombre o código"
                icon="magnifying-glass"
                maxlength="100"
            />

            <flux:select wire:model.live="stateFilter" label="Estado">
                <option value="">Todos</option>
                <option value="activo">Activos</option>
                <option value="inactivo">Inactivos</option>
            </flux:select>

            <div class="self-end">
                <flux:button wire:click="clearFilters" variant="ghost">
                    Limpiar
                </flux:button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <tr>
                        <th class="px-4 py-3">Medio de pago</th>
                        <th class="px-4 py-3">Tipo</th>
                        <th class="px-4 py-3">Referencia</th>
                        <th class="px-4 py-3">Estado</th>
                        <th class="px-4 py-3 text-right">Acciones</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($medios as $medio)
                        <tr wire:key="medio-pago-{{ $medio->id }}">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $medio->nombre }}</p>
                                <p class="text-xs text-zinc-500">
                                    {{ $medio->codigo }}
                                </p>
                            </td>

                            <td class="px-4 py-3">
                                {{ $medio->afecta_efectivo ? 'Efectivo' : 'No efectivo' }}
                            </td>

                            <td class="px-4 py-3">
                                {{ $medio->requiere_referencia || in_array($medio->codigo, ['yape', 'plin'], true) ? 'Obligatoria' : 'Opcional' }}
                            </td>

                            <td class="px-4 py-3">
                                <x-estado
                                    :estado="$medio->activo ? 'activo' : 'inactivo'"
                                    size="sm"
                                >
                                    {{ $medio->activo ? 'Activo' : 'Inactivo' }}
                                </x-estado>
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="pencil-square"
                                        wire:click="editRecord({{ $medio->id }})"
                                        aria-label="Editar {{ $medio->nombre }}"
                                    />
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        :icon="$medio->activo ? 'no-symbol' : 'check-circle'"
                                        wire:click="requestStateChange({{ $medio->id }})"
                                        :aria-label="($medio->activo ? 'Desactivar ' : 'Activar ').$medio->nombre"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-zinc-500">
                                No se encontraron medios de pago.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
            {{ $medios->links() }}
        </div>
    </section>

    <p class="text-xs text-zinc-500">
        Los medios utilizados anteriormente no se eliminan.
        Sus pagos y movimientos históricos se conservan.
    </p>

    <flux:modal
        wire:model.self="showForm"
        name="formulario-medio-pago"
        class="w-full md:min-w-[32rem]"
        :dismissible="false"
    >
        <form wire:submit="save" class="space-y-4">

            <flux:heading size="lg">
                {{ $medioId ? 'Editar medio de pago' : 'Nuevo medio de pago' }}
            </flux:heading>

            <flux:error name="seguridad" />

            <flux:input
                wire:model="nombre"
                label="Nombre"
                placeholder="Ej. POS Izipay"
                maxlength="80"
                required
            />

            <flux:input
                wire:model="codigo"
                label="Código"
                placeholder="Ej. izipay"
                maxlength="30"
                :disabled="$medioId !== null"
                required
            />

            <flux:checkbox
                wire:model="afectaEfectivo"
                label="Es un medio de efectivo"
                :disabled="$medioId !== null"
            />

            <flux:checkbox
                wire:model="requiereReferencia"
                label="Exigir número de operación"
                :disabled="in_array($codigo, ['yape', 'plin'], true)"
            />

            <flux:checkbox
                wire:model="activo"
                label="Medio activo"
            />

            <p class="text-xs text-zinc-500">
                El código y el tipo de efectivo no se pueden cambiar después
                de registrar el medio. Yape y Plin siempre requieren operación.
            </p>

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button
                    type="button"
                    wire:click="$set('showForm', false)"
                >
                    Cancelar
                </flux:button>

                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="save"
                >
                    Guardar
                </flux:button>
            </div>
        </form>
    </flux:modal>

</div>
