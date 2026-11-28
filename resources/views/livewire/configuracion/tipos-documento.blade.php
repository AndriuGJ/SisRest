<div class="mx-auto w-full max-w-7xl space-y-5">

    <header>
        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">
            Configuración del restaurante
        </p>

        <h1 class="text-2xl font-semibold">
            Tipos de documento
        </h1>

        <p class="mt-1 text-sm text-zinc-500">
            Define los documentos disponibles al realizar un cobro.
        </p>
    </header>

    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">

                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <tr>
                        <th class="px-4 py-3">Documento</th>
                        <th class="px-4 py-3">Código</th>
                        <th class="px-4 py-3">Tipo</th>
                        <th class="px-4 py-3">Estado</th>
                        <th class="px-4 py-3 text-right">Acciones</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">

                    <tr>
                        <td class="px-4 py-3 font-medium">
                            Nota de venta
                        </td>
                        <td class="px-4 py-3 text-zinc-500">
                            interno
                        </td>
                        <td class="px-4 py-3">
                            Documento interno
                        </td>
                        <td class="px-4 py-3">
                            <x-estado estado="activo" size="sm">
                                Disponible
                            </x-estado>
                        </td>
                        <td class="px-4 py-3 text-right text-xs text-zinc-500">
                            Fijo del sistema
                        </td>
                    </tr>

                    @foreach ($tipos as $tipo)
                        <tr wire:key="documento-venta-{{ $tipo->id }}">
                            <td class="px-4 py-3 font-medium">
                                {{ $tipo->nombre }}
                            </td>

                            <td class="px-4 py-3 text-zinc-500">
                                {{ $tipo->codigo }}
                            </td>

                            <td class="px-4 py-3">
                                Electrónico
                            </td>

                            <td class="px-4 py-3">
                                <x-estado
                                    :estado="$tipo->disponible_venta ? 'activo' : 'inactivo'"
                                    size="sm"
                                >
                                    {{ $tipo->disponible_venta ? 'Habilitado' : 'Deshabilitado' }}
                                </x-estado>
                            </td>

                            <td class="px-4 py-3 text-right">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    :icon="$tipo->disponible_venta ? 'no-symbol' : 'check-circle'"
                                    wire:click="requestStateChange({{ $tipo->id }})"
                                    :aria-label="($tipo->disponible_venta ? 'Deshabilitar ' : 'Habilitar ').$tipo->nombre"
                                />
                            </td>
                        </tr>
                    @endforeach

                </tbody>
            </table>
        </div>
    </section>

    <p class="text-xs text-zinc-500">
        Habilitar un documento permite seleccionarlo en nuevas ventas.
        Para emitirlo electrónicamente también necesitas la configuración
        fiscal y una serie válida. Los comprobantes ya emitidos se conservan.
    </p>

    <div class="flex justify-end">
        @can('configuracion.sistema')
            <flux:button
                variant="outline"
                icon="cog-6-tooth"
                :href="route('configuracion.facturacion')"
                wire:navigate
            >
                Configurar facturación electrónica
            </flux:button>
        @endcan
    </div>

</div>
