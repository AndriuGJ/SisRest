<div class="mx-auto w-full max-w-5xl space-y-6">
    <form wire:submit="save" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4"><div><p class="text-xs uppercase tracking-wide text-zinc-500">Compras</p><flux:heading size="xl" level="1">{{ $proveedorId ? 'Editar proveedor' : 'Nuevo proveedor' }}</flux:heading></div><div class="flex gap-2"><flux:button href="{{ route('proveedores.index') }}" wire:navigate>Cancelar</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled">Guardar proveedor</flux:button></div></div>
        @if($errors->any())<div role="alert" class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-200">{{ $errors->first() }}</div>@endif
        <div class="grid gap-5 rounded-lg border border-zinc-200 p-5 sm:grid-cols-2 dark:border-zinc-700">
            <div class="sm:col-span-2"><flux:input wire:model="nombre" label="Nombre o razón social" maxlength="200" /></div>
            <flux:select wire:model="tipoId" label="Tipo de documento"><option value="">Sin documento</option>@foreach($tipos as $tipo)<option value="{{ $tipo->id }}">{{ $tipo->nombre }}{{ $tipo->activo ? '' : ' (inactivo)' }}</option>@endforeach</flux:select>
            <flux:input wire:model="numero" label="Número de documento" maxlength="20" />
            <flux:input wire:model="telefono" label="Teléfono" maxlength="30" /><flux:input wire:model="correo" label="Correo" type="email" maxlength="150" />
            <div class="sm:col-span-2"><flux:input wire:model="direccion" label="Dirección" maxlength="250" /></div><flux:checkbox wire:model="activo" label="Proveedor activo" />
        </div><p class="text-xs text-zinc-500">El documento es opcional. Desactivar impide nuevas compras y confirmaciones; conserva compras, saldos y pagos anteriores.</p>
    </form>
</div>
