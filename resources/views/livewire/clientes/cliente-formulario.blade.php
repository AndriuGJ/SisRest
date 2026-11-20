<div class="mx-auto w-full max-w-5xl space-y-5">
    <form wire:submit="save" class="space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div class="min-w-0 flex-1"><p class="text-xs uppercase tracking-wide text-zinc-500">Clientes</p><flux:heading level="1" size="xl">{{ $clienteId ? 'Editar cliente' : 'Nuevo cliente' }}</flux:heading><flux:text class="mt-1 break-words">Datos de identificación y contacto del cliente.</flux:text></div><div class="flex flex-wrap gap-2"><flux:button href="{{ route('clientes.index') }}" wire:navigate>Cancelar</flux:button><flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">Guardar cliente</flux:button></div></div>
        @if($errors->any())<div role="alert" class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-200">{{ $errors->first() }}</div>@endif
        @if($vinculado)<p class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">Este cliente ya tiene pedidos o cobros. Su tipo y documento registrado se conservan; puedes completar un documento pendiente y actualizar sus datos de contacto.</p>@endif
        <div class="grid gap-5 rounded-lg border border-zinc-200 p-5 sm:grid-cols-2 dark:border-zinc-700">
            <flux:select wire:model.live="tipo" label="Tipo de cliente" :disabled="$vinculado"><option value="persona">Persona natural</option><option value="empresa">Empresa</option></flux:select>
            <flux:input wire:model="nombre" :label="$tipo === 'empresa' ? 'Razón social' : 'Nombres y apellidos'" maxlength="200" />
            <flux:select wire:model.live="tipoId" label="Tipo de documento" :disabled="$documentoProtegido || $tipo === 'empresa'"><option value="">Sin documento</option>@foreach($tipos as $documento)<option value="{{ $documento->id }}">{{ $documento->nombre }}{{ $documento->activo ? '' : ' (inactivo)' }}</option>@endforeach</flux:select>
            <flux:input wire:model="numero" :label="$tipo === 'empresa' ? 'RUC' : 'Número de documento'" maxlength="20" :readonly="$documentoProtegido" />
            <flux:input wire:model="telefono" label="Teléfono (opcional)" type="tel" maxlength="30" /><flux:input wire:model="correo" label="Correo (opcional)" type="email" maxlength="150" />
            <div class="sm:col-span-2"><flux:input wire:model="direccion" :label="$tipo === 'empresa' ? 'Dirección de la empresa' : 'Dirección (opcional)'" maxlength="250" /></div>
            <flux:checkbox wire:model="activo" label="Cliente activo" />
        </div><p class="text-xs leading-relaxed text-zinc-500">{{ $tipo === 'empresa' ? 'La empresa requiere RUC de 11 dígitos, razón social y dirección.' : 'El documento es opcional. Una persona natural también puede registrarse con RUC.' }} Desactivar conserva la información y el historial.</p>
    </form>
</div>
