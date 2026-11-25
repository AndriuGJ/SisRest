@props(['resumen', 'confirmar', 'cancelar' => 'cancelarConfirmacionAnulacion'])
<flux:modal wire:model.self="showConfirmCancel" class="w-full md:max-w-lg" :dismissible="false">
    <div class="space-y-4 text-center" x-data>
        <div aria-hidden="true" class="mx-auto flex size-16 items-center justify-center rounded-full border-2 border-orange-200 text-4xl text-orange-400">!</div>
        <flux:heading level="2" size="lg">Confirmar anulación</flux:heading>
        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $resumen }}</p>
        @if($errors->any())<p role="alert" class="text-sm font-medium text-red-700">{{ $errors->first() }}</p>@endif
        <div class="mx-auto max-w-xs space-y-2 text-left">
            <label for="clave-{{ $confirmar }}" class="block text-sm font-medium">Clave de autorización</label>
            <input id="clave-{{ $confirmar }}" type="password" x-ref="clave" inputmode="numeric" maxlength="12" autocomplete="off"
                class="w-full rounded border border-zinc-300 bg-white px-3 py-2 text-center text-lg focus:border-red-500 focus:outline-none dark:border-zinc-600 dark:bg-zinc-800" />
        </div>
        <div class="flex justify-end gap-2">
            <flux:button type="button" wire:click="{{ $cancelar }}" x-on:click="$refs.clave.value = ''" wire:loading.attr="disabled">Cancelar</flux:button>
            <flux:button variant="danger" x-on:click="const clave = $refs.clave.value; $refs.clave.value = ''; $wire.{{ $confirmar }}(clave)" wire:target="{{ $confirmar }}" wire:loading.attr="disabled">Confirmar anulación</flux:button>
        </div>
    </div>
</flux:modal>
