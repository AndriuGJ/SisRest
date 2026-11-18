<div class="mx-auto w-full max-w-7xl space-y-5">
    <form wire:submit="save" class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div><p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">INVENTARIO</p><h1 class="text-2xl font-semibold tracking-tight">{{ $preparacionId ? 'Editar borrador' : 'Nueva preparación' }}</h1><p class="mt-1 text-sm text-zinc-500">Calcula los ingredientes y revisa el consumo real antes de confirmar la producción.</p></div>
            <div class="flex gap-2"><flux:button href="{{ $preparacionId ? route('preparaciones.ver', $preparacionId) : route('preparaciones.index') }}" wire:navigate>Cancelar</flux:button><flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save,calcular">Guardar borrador</flux:button></div>
        </header>
        <flux:error name="seguridad" /><flux:error name="consumos" /><flux:error name="clave" />
        <section class="space-y-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid items-start gap-4 md:grid-cols-[minmax(15rem,1fr)_12rem_12rem_auto]">
                <flux:select wire:model.live="productoId" label="Producto a preparar" :disabled="$preparacionId !== null" required><option value="">Seleccionar producto con receta</option>@foreach($productos as $producto)<option value="{{ $producto->id }}">{{ $producto->nombre }}</option>@endforeach</flux:select>
                <flux:input wire:model="cantidad" label="Cantidad producida" type="number" min="0.0001" step="0.0001" required />
                <flux:input wire:model="fecha" label="Fecha de preparación" type="date" max="{{ now()->format('Y-m-d') }}" required />
                <flux:button type="button" variant="ghost" icon="calculator" wire:click="calcular" wire:loading.attr="disabled" wire:target="calcular" class="mt-6">Calcular ingredientes</flux:button>
            </div>
            <p class="text-xs text-zinc-500">Solo aparecen productos preparados con receta activa. Volver a calcular utiliza la receta vigente y restablece las cantidades estándar.</p>
            <flux:textarea wire:model="observaciones" label="Observaciones de la preparación" badge="Opcional" rows="2" maxlength="2000" />
        </section>
        <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700"><h2 class="text-sm font-semibold">Revisión de ingredientes</h2><p class="mt-1 text-xs text-zinc-500">Todas las cantidades están en la unidad de inventario de cada ingrediente. La disponibilidad se vuelve a comprobar al confirmar.</p></div>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm"><caption class="sr-only">Consumos de la preparación</caption><thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800/60"><tr><th class="px-4 py-3 font-medium">Ingrediente</th><th class="px-4 py-3 font-medium">Unidad</th><th class="px-4 py-3 font-medium">Estándar</th><th class="px-4 py-3 font-medium">Real utilizado</th><th class="px-4 py-3 font-medium">Disponible</th></tr></thead><tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($filas as $fila)
                    <tr wire:key="consumo-{{ $fila['articuloId'] }}"><td class="px-4 py-3 font-medium">{{ $fila['nombre'] }}</td><td class="px-4 py-3 text-zinc-500">{{ $fila['unidad'] }}</td><td class="px-4 py-3">{{ \App\Support\Cantidad::mostrar($fila['estandar']) }}</td><td class="min-w-40 px-4 py-3"><flux:input wire:model="consumos.{{ $fila['articuloId'] }}" type="number" min="0" step="0.0001" aria-label="Consumo real de {{ $fila['nombre'] }}" :disabled="! auth()->user()->can('preparaciones.ajustar_consumos')" /></td><td class="px-4 py-3">{{ \App\Support\Cantidad::mostrar($fila['disponible']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-zinc-500">Selecciona el producto y pulsa Calcular ingredientes.</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
        @can('preparaciones.ajustar_consumos')
            <flux:textarea wire:model="motivoAjuste" label="Motivo del ajuste de consumos" badge="Solo si cambias cantidades" rows="2" maxlength="500" />
        @endcan
        <p class="text-xs text-zinc-500">Guardar el borrador no consume ingredientes ni aumenta existencias. La confirmación se realiza desde el detalle de la preparación.</p>
    </form>
</div>
