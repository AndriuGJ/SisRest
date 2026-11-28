@props(['filas', 'unidad'])
<section class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div><h2 class="text-sm font-semibold">Presentaciones de compra</h2><p class="mt-1 text-xs text-zinc-500">Indica cuántos {{ $unidad }} contiene cada presentación. Ejemplo: con inventario en g, un saco de 50 kg equivale a 50000 g.</p></div>
        <flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="addPresentation">Agregar presentación</flux:button>
    </div>
    <flux:error name="presentaciones" />
    <div class="space-y-4">
        @forelse($filas as $clave => $fila)
            <div wire:key="presentacion-{{ $clave }}" class="grid items-start gap-3 border-t border-zinc-100 pt-4 sm:grid-cols-[1fr_10rem_auto_auto] dark:border-zinc-800">
                <flux:input wire:model="presentaciones.{{ $clave }}.nombre" label="Nombre de presentación" placeholder="Ej. saco de 50 kg" maxlength="100" />
                <flux:input wire:model="presentaciones.{{ $clave }}.factor" label="Equivale a ({{ $unidad }})" type="number" min="0.0001" step="0.0001" />
                <div class="pt-7"><flux:checkbox wire:model="presentaciones.{{ $clave }}.activo" label="Activa" /></div>
                <flux:button type="button" size="sm" variant="ghost" icon="x-mark" wire:click="removePresentation('{{ $clave }}')" aria-label="Retirar presentación {{ $fila['nombre'] }}" class="mt-6" />
                <div class="sm:col-span-4"><flux:error name="presentaciones.{{ $clave }}.id" /></div>
            </div>
        @empty
            <p class="text-sm text-zinc-500">Sin presentaciones adicionales. Podrás comprar directamente en {{ $unidad }}.</p>
        @endforelse
    </div>
</section>
