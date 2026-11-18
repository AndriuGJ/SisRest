<div class="mx-auto w-full max-w-7xl space-y-6">
    <form wire:submit="save" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-4"><div><p class="text-xs uppercase tracking-wide text-zinc-500">Compras</p><flux:heading size="xl" level="1">{{ $compraId ? 'Editar borrador' : 'Nueva compra' }}</flux:heading><flux:text class="mt-1">Registra insumos o productos de reventa que ya recibiste.</flux:text></div><div class="flex gap-2"><flux:button href="{{ $compraId ? route('compras.ver', $compraId) : route('compras.index') }}" wire:navigate>Cancelar</flux:button><flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">Guardar borrador</flux:button></div></div>
        @if($errors->any())<div role="alert" class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-200">{{ $errors->first() }}</div>@endif
        <div class="grid gap-4 rounded-lg border border-zinc-200 p-5 md:grid-cols-3 dark:border-zinc-700">
            <flux:select wire:model.live="proveedorId" label="Proveedor"><option value="">Seleccionar proveedor</option>@foreach($proveedores as $proveedor)<option value="{{ $proveedor->id }}">{{ $proveedor->nombre }}{{ $proveedor->activo ? '' : ' (inactivo)' }}</option>@endforeach</flux:select>
            <flux:input wire:model="fecha" type="date" label="Fecha de compra" />
            <flux:select wire:model.live="condicion" label="Condición de pago"><option value="contado">Contado</option><option value="credito">Crédito</option></flux:select>
            <flux:select wire:model="tipoId" label="Documento recibido">@foreach($tipos as $tipo)<option value="{{ $tipo->id }}">{{ $tipo->nombre }}</option>@endforeach</flux:select><flux:input wire:model="serie" label="Serie" maxlength="10" /><flux:input wire:model="numero" label="Número" maxlength="30" />
            @if($condicion === 'credito')<flux:input wire:model="vencimiento" type="date" label="Fecha de vencimiento" />@endif
        </div>
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700"><div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700"><flux:heading level="2">Artículos recibidos</flux:heading><flux:button size="sm" wire:click="addRow" icon="plus">Agregar artículo</flux:button></div><div class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @forelse($filas as $clave => $fila)
            @php($articulo = $articulos->firstWhere('id', (int) ($fila['articuloId'] ?? 0)))
            @php($presentacion = $articulo?->presentaciones->firstWhere('id', (int) ($fila['presentacionId'] ?? 0)))
            <div wire:key="compra-fila-{{ $clave }}" class="space-y-3 p-5">
                <div class="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-[2fr_1.4fr_0.8fr_1fr_auto]">
                    <flux:select wire:model.live="filas.{{ $clave }}.articuloId" label="Artículo"><option value="">Seleccionar artículo</option>@foreach($articulos as $opcion)<option value="{{ $opcion->id }}">{{ $opcion->nombre }} · {{ $opcion->codigo }}{{ $opcion->activo ? '' : ' (inactivo)' }}</option>@endforeach</flux:select>
                    <flux:select wire:model.live="filas.{{ $clave }}.presentacionId" label="Presentación"><option value="">{{ $articulo?->unidadMedida->nombre ?? 'Unidad de inventario' }}</option>@foreach($articulo?->presentaciones ?? [] as $opcion)<option value="{{ $opcion->id }}">{{ $opcion->nombre }}{{ $opcion->activo ? '' : ' (inactiva)' }}</option>@endforeach</flux:select>
                    <flux:input wire:model="filas.{{ $clave }}.cantidad" label="Cantidad" inputmode="decimal" /><flux:input wire:model="filas.{{ $clave }}.costo" label="Precio por presentación (S/)" inputmode="decimal" />
                    <flux:button variant="ghost" icon="trash" wire:click="removeRow('{{ $clave }}')" aria-label="Quitar artículo {{ $loop->iteration }}" />
                </div>
                @if($articulo)<p class="text-xs text-zinc-500">1 {{ $presentacion?->nombre ?? $articulo->unidadMedida->nombre }} = {{ \App\Support\Cantidad::mostrar($presentacion?->factor_conversion ?? '1') }} {{ $articulo->unidadMedida->codigo }}. El ingreso se registra en {{ $articulo->unidadMedida->codigo }}.</p>@endif
                <details class="text-sm"><summary class="cursor-pointer text-zinc-500">Descuento e impuesto incluido</summary><div class="mt-3 grid gap-4 sm:max-w-xl sm:grid-cols-2"><flux:input wire:model="filas.{{ $clave }}.descuento" label="Descuento de esta línea (S/)" inputmode="decimal" /><flux:input wire:model="filas.{{ $clave }}.impuestos" label="Impuesto ya incluido (S/)" inputmode="decimal" /></div></details>
            </div>
        @empty<div class="p-5 text-sm text-zinc-500">Agrega al menos un artículo.</div>@endforelse
        </div></div>
        <flux:textarea wire:model="observaciones" label="Observaciones" rows="2" maxlength="2000" />
        <p class="text-xs leading-relaxed text-zinc-500">Los precios son finales, con los impuestos que correspondan incluidos. El impuesto indicado solo se desglosa; no se suma otra vez. Los totales se calculan al guardar y se revisan antes de confirmar. Una compra al contado se confirma junto con su pago; una compra al crédito permite pagos posteriores.</p>
    </form>
</div>
