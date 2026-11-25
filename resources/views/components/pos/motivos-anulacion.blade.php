@props(['motivos', 'seleccionado', 'model' => 'motivoAnulacionId'])
<div class="space-y-2">
    <p class="text-sm font-medium">Motivo de la anulación</p>
    <div class="grid gap-2 sm:grid-cols-2" role="group" aria-label="Motivos de anulación">
        @forelse($motivos as $opcion)
            <button type="button" wire:click="$set('{{ $model }}', '{{ $opcion->id }}')" aria-pressed="{{ (string)$seleccionado === (string)$opcion->id ? 'true' : 'false' }}"
                class="border px-3 py-2 text-left text-sm font-medium {{ (string)$seleccionado === (string)$opcion->id ? 'border-red-500 bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-200' : 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200' }}">
                {{ $opcion->nombre }}
            </button>
        @empty<p class="text-sm text-amber-700">Configura al menos un motivo activo para continuar.</p>@endforelse
    </div>
</div>
