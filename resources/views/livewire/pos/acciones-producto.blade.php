<div>
    <flux:modal wire:model="showModal" class="md:w-lg">
        <div class="space-y-5">
            <div><flux:heading level="2" size="lg">{{ $modo === 'anular' ? 'Anular producto confirmado' : 'Beneficio del producto' }}</flux:heading><flux:text class="mt-1">{{ $detalle?->descripcion }}</flux:text></div>
            @if($errors->any())<p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $errors->first() }}</p>@endif
            @if($modo === 'anular')
                <flux:input wire:model="cantidad" type="number" min="1" max="9999" step="1" label="Cantidad a anular" aria-label="Cantidad a anular" />
                <p class="text-sm text-zinc-500">No se anulan cantidades cobradas o entregadas. Primero se libera lo no iniciado; lo iniciado/listo se registra como merma y exige ese permiso. No se restituyen ingredientes.</p>
            @else
                <flux:select wire:model.live="accion" label="Acción del beneficio" aria-label="Acción del beneficio">@can('descuentos.aplicar')<option value="descuento">Aplicar descuento</option>@endcan @can('cortesias.aplicar')<option value="cortesia">Autorizar cortesía</option>@endcan<option value="quitar">Retirar beneficio anterior</option></flux:select>
                @if($accion === 'descuento')<div class="grid gap-3 sm:grid-cols-2"><flux:select wire:model="tipo" label="Tipo de descuento" aria-label="Tipo de descuento"><option value="porcentaje">Porcentaje</option><option value="soles">Soles</option></flux:select><flux:input wire:model="valor" inputmode="decimal" label="Valor del descuento" aria-label="Valor del descuento" /></div><p class="text-xs text-zinc-500">En soles se indica el descuento total para esta línea, no por unidad. Cubrir el importe completo exige también permiso de cortesía.</p>@endif
                @if($accion !== 'quitar')<flux:select wire:model="motivoId" label="Motivo del beneficio" aria-label="Motivo del beneficio"><option value="">Seleccionar motivo</option>@foreach($motivos as $motivoOpcion)<option value="{{ $motivoOpcion->id }}">{{ $motivoOpcion->nombre }}</option>@endforeach</flux:select>@endif
                <p class="text-sm text-zinc-500">Aplica a las unidades vigentes de la línea antes de su primer cobro. Conserva el precio original y no cambia inventario. Una cortesía se entrega normalmente y se liquida sin dinero.</p>
            @endif
            <flux:textarea wire:model="motivo" aria-label="{{ $modo === 'anular' ? 'Motivo de la anulación' : 'Detalle de la autorización' }}" label="{{ $modo === 'anular' ? 'Motivo de la anulación' : 'Detalle de la autorización' }}" rows="3" maxlength="500" />
            <div class="flex flex-wrap justify-end gap-2"><flux:button wire:click="$set('showModal', false)">Cancelar</flux:button><flux:button variant="primary" wire:click="review" wire:loading.attr="disabled">Revisar y confirmar</flux:button></div>
        </div>
    </flux:modal>
</div>
