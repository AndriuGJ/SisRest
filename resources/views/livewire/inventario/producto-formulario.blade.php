<div class="mx-auto w-full max-w-7xl space-y-5">
    <form wire:submit="save" class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div><p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">INVENTARIO</p><h1 class="text-2xl font-semibold tracking-tight">{{ $productoId ? 'Editar producto' : 'Nuevo producto' }}</h1><p class="mt-1 text-sm text-zinc-500">Define lo que ofreces, su precio y cómo se atiende.</p></div>
            <div class="flex gap-2"><flux:button href="{{ route('productos.index') }}" wire:navigate>Cancelar</flux:button><flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save,foto">Guardar producto</flux:button></div>
        </header>
        <flux:error name="seguridad" />
        <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <section class="space-y-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="grid items-start gap-4 sm:grid-cols-[11rem_1fr]"><flux:input wire:model="codigo" label="Código" placeholder="Ej. P-001" :maxlength="$productoId ? 40 : 37" :readonly="$productoId !== null" required /><flux:input wire:model="nombre" label="Nombre del producto" placeholder="Ej. Chaufa de pollo" maxlength="150" required /></div>
                <div class="grid items-start gap-4 sm:grid-cols-3">
                    <flux:select wire:model.live="tipo" label="Tipo de producto" :disabled="$productoId !== null" required><option value="plato">Plato</option><option value="bebida">Bebida</option><option value="reventa">Reventa</option><option value="menu">Menú fijo</option><option value="adicional">Adicional</option><option value="combo">Combo</option></flux:select>
                    <flux:select wire:model="categoriaId" label="Categoría" required><option value="">Seleccionar categoría</option>@foreach($categorias as $categoria)<option value="{{ $categoria->id }}">{{ $categoria->nombre }}{{ $categoria->activo ? '' : ' (inactiva)' }}</option>@endforeach</flux:select>
                    <flux:input wire:model="precio" label="Precio final (S/)" type="number" min="0" step="0.01" required />
                </div>
                <flux:textarea wire:model="descripcion" label="Descripción" badge="Opcional" rows="2" maxlength="2000" />
                @if($tipo !== 'combo')
                    <div wire:key="producto-datos-existencias" class="space-y-4 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                        <div class="grid items-start gap-4 sm:grid-cols-3">
                            <flux:select wire:model.live="origen" label="Origen de existencias" :disabled="$productoId !== null || in_array($tipo, ['plato', 'menu', 'reventa'], true)" required><option value="preparado">Preparación diaria</option><option value="reventa">Se compra listo</option></flux:select>
                            <flux:select wire:model.live="unidadId" label="Unidad de venta" :disabled="$productoId !== null" required><option value="">Seleccionar unidad</option>@foreach($unidades as $unidad)<option value="{{ $unidad->id }}">{{ $unidad->nombre }}</option>@endforeach</flux:select>
                            <flux:input wire:model="minimo" label="Existencia mínima" type="number" min="0" step="0.0001" required />
                        </div>
                        <flux:select wire:model="destinoId" label="Destino de atención"><option value="">Entrega directa · sin comanda</option>@foreach($destinos as $destino)<option value="{{ $destino->id }}">{{ $destino->nombre }}{{ $destino->activo ? '' : ' (inactivo)' }}</option>@endforeach</flux:select>
                        <p class="text-xs text-zinc-500">{{ $origen === 'reventa' ? 'Las compras ingresan unidades listas para vender.' : 'La preparación diaria consume los insumos e ingresa las porciones disponibles.' }} Guardar este formulario no cambia las existencias.</p>
                    </div>
                @else
                    <p class="text-xs text-zinc-500">El combo utiliza las existencias y destinos de sus componentes. No tiene inventario propio.</p>
                @endif
                <div class="flex flex-wrap gap-5 border-t border-zinc-100 pt-4 dark:border-zinc-800"><flux:checkbox wire:model="visible" label="Visible en POS" /><flux:checkbox wire:model="activo" label="Producto activo" /></div>
            </section>
            <section class="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-sm font-semibold">Fotografía</h2>
                @if($foto && $foto->isPreviewable())
                    <img src="{{ $foto->temporaryUrl() }}" alt="Vista previa del producto" class="aspect-square w-full rounded-md object-cover" />
                @elseif($imagenActual && ! $quitarFoto)
                    <img src="{{ route('productos.imagen', ['producto' => $productoId, 'v' => substr(hash('sha256', $imagenActual), 0, 16)], false) }}" alt="Fotografía del producto" class="aspect-square w-full rounded-md object-cover" />
                @else
                    <div class="flex aspect-square items-center justify-center rounded-md bg-zinc-100 text-zinc-400 dark:bg-zinc-800"><flux:icon.photo class="size-12" /></div>
                @endif
                <flux:input wire:model="foto" label="Fotografía del producto" type="file" accept="image/jpeg,image/png,image/webp" />
                <p class="text-xs text-zinc-500">JPG, PNG o WebP. Máximo 2 MB.</p>
                <div wire:loading wire:target="foto" class="text-xs text-zinc-500">Cargando fotografía…</div>
                @if($imagenActual)<flux:checkbox wire:model.live="quitarFoto" label="Quitar fotografía actual" />@endif
            </section>
        </div>
        @if($tipo === 'combo')
            <section wire:key="producto-componentes" class="space-y-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-sm font-semibold">Componentes del combo</h2><p class="mt-1 text-xs text-zinc-500">Elige los productos y cantidades que incluye. No se admiten otros combos.</p></div><flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="addComponent">Agregar componente</flux:button></div>
                <flux:error name="componentes" />
                @foreach($componentes as $clave => $fila)
                    <div wire:key="componente-{{ $clave }}" class="grid items-start gap-3 sm:grid-cols-[1fr_10rem_auto]">
                        <flux:select wire:model="componentes.{{ $clave }}.productoId" label="Producto componente"><option value="">Seleccionar producto</option>@foreach($productosComponentes as $componente)<option value="{{ $componente->id }}">{{ $componente->nombre }} · {{ $componente->codigo }}{{ $componente->activo ? '' : ' (inactivo)' }}</option>@endforeach</flux:select>
                        <flux:input wire:model="componentes.{{ $clave }}.cantidad" label="Cantidad" type="number" min="0.0001" step="0.0001" />
                        <flux:button type="button" size="sm" variant="ghost" icon="x-mark" wire:click="removeComponent('{{ $clave }}')" aria-label="Retirar componente" class="mt-6" />
                    </div>
                @endforeach
            </section>
        @elseif($origen === 'reventa')
            <x-inventario.presentaciones :filas="$presentaciones" :unidad="$unidades->firstWhere('id', $unidadId)?->codigo ?? 'unidades'" />
        @endif
    </form>
</div>
