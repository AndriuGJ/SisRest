<div class="mx-auto max-w-5xl space-y-5">
<header><flux:heading level="1" size="xl">Empresa</flux:heading><flux:text class="mt-1">Datos del restaurante y logo para identificar el sistema y sus documentos.</flux:text></header>
<form wire:submit="guardar" class="space-y-5">
@if($errors->any())<div role="alert" class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<section class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"><flux:heading level="2">Logo de la empresa</flux:heading>
@if($logo && !$errors->has('logo'))<img src="{{ $logo->temporaryUrl() }}" alt="Vista previa del nuevo logo" class="h-24 max-w-64 object-contain bg-white p-2 rounded">
@elseif($logoActual && !$quitarLogo)<img src="{{ $logoActual }}" alt="Logo actual" class="h-24 max-w-64 object-contain bg-white p-2 rounded">@endif
<flux:input wire:model="logo" label="Seleccionar logo" type="file" accept="image/png,image/jpeg,image/webp" description:trailing="PNG, JPG o WebP · máximo 2 MB y 3000 × 3000 px. Recomendamos fondo transparente." />
@if($logoActual)<flux:checkbox wire:model.live="quitarLogo" label="Quitar el logo actual" />@endif
<p class="text-xs text-zinc-500">Se utiliza en el login, sidebar, pestañas del navegador, PDF y nuevas impresiones. Los documentos históricos conservan el logo con el que fueron generados.</p></section>
<section class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"><flux:heading level="2">Datos del restaurante</flux:heading>
<div class="grid gap-4 sm:grid-cols-3"><flux:input wire:model="datos.ruc" label="RUC" maxlength="11" /><flux:input wire:model="datos.razon_social" label="Razón social" maxlength="200" required /><flux:input wire:model="datos.nombre_comercial" label="Nombre comercial" maxlength="150" /></div>
<div class="grid gap-4 sm:grid-cols-[1fr_10rem]"><flux:input wire:model="datos.direccion" label="Dirección fiscal" maxlength="250" /><flux:input wire:model="datos.ubigeo" label="Ubigeo" maxlength="6" /></div>
<div class="grid gap-4 sm:grid-cols-3"><flux:input wire:model="datos.departamento" label="Departamento" maxlength="100" /><flux:input wire:model="datos.provincia" label="Provincia" maxlength="100" /><flux:input wire:model="datos.distrito" label="Distrito" maxlength="100" /></div>
<div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="datos.telefono" label="Teléfono" maxlength="30" /><flux:input wire:model="datos.correo" label="Correo" type="email" maxlength="150" /></div>
<p class="text-xs text-zinc-500">Para habilitar facturación completa todos los datos fiscales. La conexión con SUNAT y las series se administran desde Facturación.</p></section>
<div class="flex justify-end"><flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="guardar,logo">Guardar empresa</flux:button></div>
</form></div>
