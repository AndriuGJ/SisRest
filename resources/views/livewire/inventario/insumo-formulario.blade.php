<div class="mx-auto w-full max-w-5xl space-y-5">
    <form wire:submit="save" class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div><p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">INVENTARIO</p><h1 class="text-2xl font-semibold tracking-tight">{{ $insumoId ? 'Editar insumo' : 'Nuevo insumo' }}</h1><p class="mt-1 text-sm text-zinc-500">Registra la materia prima que utilizas en tus preparaciones.</p></div>
            <div class="flex gap-2"><flux:button href="{{ route('insumos.index') }}" wire:navigate>Cancelar</flux:button><flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">Guardar insumo</flux:button></div>
        </header>
        <flux:error name="seguridad" />
        <section class="space-y-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid items-start gap-4 sm:grid-cols-[12rem_1fr]">
                <flux:input wire:model="codigo" label="Código" placeholder="Ej. INS-001" maxlength="40" :readonly="$insumoId !== null" required />
                <flux:input wire:model="nombre" label="Nombre del insumo" placeholder="Ej. Arroz" maxlength="150" required />
            </div>
            <div class="grid items-start gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="unidadId" label="Unidad de inventario" :disabled="$insumoId !== null" required><option value="">Seleccionar unidad</option>@foreach($unidades as $unidad)<option value="{{ $unidad->id }}">{{ $unidad->nombre }} ({{ $unidad->codigo }})</option>@endforeach</flux:select>
                <flux:input wire:model="minimo" label="Existencia mínima" type="number" min="0" step="0.0001" description:trailing="Referencia para avisar cuando quede poca cantidad." required />
            </div>
            <p class="text-xs text-zinc-500">El código y la unidad se conservan después de crear. Los ingresos se registran por compras; los consumos, por preparación y movimientos autorizados.</p>
            <flux:checkbox wire:model="activo" label="Insumo activo" />
        </section>
        <x-inventario.presentaciones :filas="$presentaciones" :unidad="$unidades->firstWhere('id', $unidadId)?->codigo ?? 'unidades'" />
    </form>
</div>
