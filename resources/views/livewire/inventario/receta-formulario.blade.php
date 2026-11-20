<div class="mx-auto w-full max-w-6xl space-y-5">
    <form wire:submit="save" class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div><p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">INVENTARIO · RECETA</p><h1 class="text-2xl font-semibold tracking-tight">{{ $producto->nombre }}</h1><p class="mt-1 text-sm text-zinc-500">Define los ingredientes para una cantidad de {{ $producto->articulo->unidadMedida->nombre }}. No se consumen existencias al guardar.</p></div>
            <div class="flex gap-2"><flux:button href="{{ route('productos.index') }}" wire:navigate>Cancelar</flux:button><flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">Guardar receta</flux:button></div>
        </header>
        <flux:error name="seguridad" /><flux:error name="productoId" />
        <section class="space-y-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid items-start gap-4 sm:grid-cols-[15rem_1fr]">
                <flux:input wire:model="rendimiento" label="Cantidad que rinde la receta" type="number" min="0.0001" step="0.0001" description:trailing="Ejemplo: ingredientes para 10 porciones." required />
                <flux:textarea wire:model="observaciones" label="Observaciones de la receta" badge="Opcional" rows="2" maxlength="2000" />
            </div>
            <flux:checkbox wire:model="activo" label="Receta activa" description="Permite utilizarla en nuevas preparaciones." />
        </section>
        <section class="space-y-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-sm font-semibold">Ingredientes</h2><p class="mt-1 text-xs text-zinc-500">Indica cada cantidad en la unidad de inventario mostrada junto al artículo.</p></div><flux:button type="button" variant="ghost" size="sm" icon="plus" wire:click="addIngredient">Agregar ingrediente</flux:button></div>
            <flux:error name="ingredientes" />
            @forelse($ingredientes as $clave => $fila)
                <div wire:key="ingrediente-{{ $clave }}" class="grid items-start gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto]">
                    <flux:select wire:model="ingredientes.{{ $clave }}.articuloId" label="Ingrediente"><option value="">Seleccionar artículo</option>@foreach($articulos as $articulo)<option value="{{ $articulo->id }}">{{ $articulo->nombre }} · {{ $articulo->unidadMedida->codigo }}{{ $articulo->activo ? '' : ' (inactivo)' }}</option>@endforeach</flux:select>
                    <flux:input wire:model="ingredientes.{{ $clave }}.cantidad" label="Cantidad para este rendimiento" type="number" min="0.0001" step="0.0001" />
                    <flux:button type="button" size="sm" variant="ghost" icon="x-mark" wire:click="removeIngredient('{{ $clave }}')" aria-label="Retirar ingrediente" class="mt-6" />
                </div>
            @empty
                <p class="text-sm text-zinc-500">Agrega al menos un ingrediente para definir la receta.</p>
            @endforelse
        </section>
        <p class="text-xs text-zinc-500">Puedes utilizar un preparado existente como ingrediente. Se consumirá su existencia preparada; no volverán a descontarse sus ingredientes. No se permiten dependencias circulares.</p>
    </form>
</div>
