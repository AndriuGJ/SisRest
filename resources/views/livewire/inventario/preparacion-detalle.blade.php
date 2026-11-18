<div class="mx-auto w-full max-w-7xl space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">INVENTARIO · {{ $preparacion->codigo }}</p><h1 class="text-2xl font-semibold tracking-tight">{{ $preparacion->producto_nombre ?? $preparacion->producto->nombre }}</h1><p class="mt-1 text-sm text-zinc-500">{{ $preparacion->fecha->format('d/m/Y') }} · Registrado por {{ $preparacion->usuario->name }}</p></div>
        <div class="flex flex-wrap gap-2"><flux:button href="{{ route('preparaciones.index') }}" wire:navigate>Volver al historial</flux:button>
            @if($preparacion->estado->value === 'borrador')
                @if(auth()->user()->can('preparaciones.crear') && ($preparacion->usuario_id === auth()->id() || auth()->user()->can('preparaciones.confirmar')))
                    <flux:button href="{{ route('preparaciones.editar', $preparacion) }}" wire:navigate icon="pencil-square">Editar borrador</flux:button><flux:button variant="ghost" wire:click="$set('showCancel', true)">Anular borrador</flux:button>
                @endif
                @can('preparaciones.confirmar')<flux:button variant="primary" icon="check" wire:click="requestConfirm" wire:loading.attr="disabled" wire:target="confirmProduction,requestConfirm">Confirmar producción</flux:button>@endcan
            @endif
        </div>
    </header>
    <section class="flex flex-wrap items-center gap-x-8 gap-y-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div><p class="text-xs text-zinc-500">Estado</p><flux:badge class="mt-1" :color="['borrador' => 'zinc', 'confirmada' => 'green', 'anulada' => 'red'][$preparacion->estado->value]">{{ ucfirst($preparacion->estado->value) }}</flux:badge></div>
        <div><p class="text-xs text-zinc-500">Cantidad producida</p><p class="mt-1 font-semibold">{{ \App\Support\Cantidad::mostrar($preparacion->cantidad_producida) }} {{ $preparacion->unidad_codigo }}</p></div>
        @if($preparacion->confirmada_at)<div><p class="text-xs text-zinc-500">Confirmación</p><p class="mt-1 text-sm">{{ $preparacion->confirmada_at->format('d/m/Y H:i') }} · {{ $preparacion->confirmadaPor?->name }}</p></div>@endif
        @can('costos.ver')@if($preparacion->estado->value === 'confirmada')<div><p class="text-xs text-zinc-500">Costo total de producción</p><p class="mt-1 font-semibold">S/ {{ $preparacion->costo_total }}</p></div>@endif@endcan
    </section>
    @if($preparacion->estado->value === 'borrador')<p class="text-sm text-zinc-500">Este borrador conserva la receta calculada al guardarlo. Todavía no modifica existencias. Al editarlo puedes recalcular con la receta vigente.</p>@endif
    @if($preparacion->observaciones)<p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $preparacion->observaciones }}</p>@endif
    @if($preparacion->motivo_ajuste)<p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300"><strong>Ajuste de consumos:</strong> {{ $preparacion->motivo_ajuste }}</p>@endif
    @if($preparacion->motivo_anulacion)<p class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"><strong>Motivo de anulación:</strong> {{ $preparacion->motivo_anulacion }}</p>@endif
    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <h2 class="border-b border-zinc-200 px-4 py-3 text-sm font-semibold dark:border-zinc-700">Ingredientes de esta preparación</h2>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><caption class="sr-only">Consumos registrados</caption><thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800/60"><tr><th class="px-4 py-3 font-medium">Ingrediente</th><th class="px-4 py-3 font-medium">Unidad</th><th class="px-4 py-3 font-medium">Estándar</th><th class="px-4 py-3 font-medium">Real utilizado</th>@can('costos.ver')@if($preparacion->estado->value === 'confirmada')<th class="px-4 py-3 font-medium">Costo unitario</th><th class="px-4 py-3 font-medium">Costo total</th>@endif@endcan</tr></thead><tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach($preparacion->detalles as $detalle)
                <tr><td class="px-4 py-3 font-medium">{{ $detalle->descripcion ?? $detalle->articulo->nombre }}</td><td class="px-4 py-3 text-zinc-500">{{ $detalle->unidad_codigo ?? $detalle->articulo->unidadMedida->codigo }}</td><td class="px-4 py-3">{{ \App\Support\Cantidad::mostrar($detalle->cantidad_estandar) }}</td><td class="px-4 py-3">{{ \App\Support\Cantidad::mostrar($detalle->cantidad_utilizada) }}</td>@can('costos.ver')@if($preparacion->estado->value === 'confirmada')<td class="px-4 py-3">S/ {{ $detalle->costo_unitario }}</td><td class="px-4 py-3">S/ {{ $detalle->costo_total }}</td>@endif@endcan</tr>
            @endforeach
        </tbody></table></div>
    </section>
    <flux:modal wire:model.self="showCancel" name="preparacion-anular" class="w-full md:min-w-[28rem]" :dismissible="false">
        <form wire:submit="requestCancel" class="space-y-5"><flux:heading size="lg">Anular borrador</flux:heading><flux:textarea wire:model="motivo" label="Motivo de anulación" rows="3" maxlength="500" required /><div class="flex justify-end gap-2"><flux:button type="button" wire:click="$set('showCancel', false)">Cancelar</flux:button><flux:button type="submit" variant="danger">Continuar</flux:button></div></form>
    </flux:modal>
</div>
