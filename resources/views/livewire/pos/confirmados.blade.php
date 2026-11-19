<div class="space-y-3">
    @foreach(($confirmadosVista ?? $pedido->detalles->whereNull('detalle_padre_id')->filter(fn($d) => $d->estado->value !== 'borrador')) as $detalle)
        <div wire:key="confirmado-{{ $detalle->id }}" class="space-y-3 rounded-md border border-zinc-200 p-3 text-sm dark:border-zinc-700">
            <div><p class="font-medium">{{ $detalle->descripcion }} × {{ \App\Support\Cantidad::mostrar($detalle->cantidad) }}</p><p class="mt-1 text-xs text-zinc-500">{{ ucfirst($detalle->estado->value) }} · S/ {{ $detalle->precio_unitario }} por unidad</p>@if($detalle->nota)<p class="mt-1 text-xs">{{ $detalle->nota }}</p>@endif</div>
            @if(bccomp($detalle->cantidad_anulada, '0', 4) > 0)<p class="text-xs text-red-700 dark:text-red-300">Anulados: {{ \App\Support\Cantidad::mostrar($detalle->cantidad_anulada) }}. Vigentes: {{ \App\Support\Cantidad::mostrar($detalle->cantidadVigente()) }}.</p>@endif
            @if(bccomp($detalle->cantidad_cancelada_pagada,'0',4)>0)<p class="text-xs text-amber-700 dark:text-amber-300">Pagados con entrega cancelada: {{ \App\Support\Cantidad::mostrar($detalle->cantidad_cancelada_pagada) }}</p>@endif
            @if(bccomp($detalle->cantidad_devuelta,'0',4)>0)<p class="text-xs text-amber-700 dark:text-amber-300">Retornos físicos: {{ \App\Support\Cantidad::mostrar($detalle->cantidad_devuelta) }}</p>@endif
            @if($detalle->beneficio_autorizado_at)<div class="rounded bg-zinc-50 p-2 text-xs dark:bg-zinc-900"><p class="font-medium">{{ $detalle->es_cortesia ? 'Cortesía autorizada' : 'Descuento autorizado' }} · S/ {{ $detalle->descuento_importe }} en esta línea</p><p class="mt-1">{{ $detalle->beneficio_motivo_nombre }} · {{ $detalle->beneficio_autorizado_nombre }}</p><p class="mt-1 text-zinc-500">{{ $detalle->beneficio_motivo_texto }}</p></div>@endif
            @if($detalle->estado->value === 'confirmado')
                @if($detalle->hijos->isNotEmpty())<p class="text-xs text-zinc-500">Combos completos entregados: {{ \App\Support\Cantidad::mostrar($detalle->cantidad_entregada) }}</p>@endif
                @foreach($detalle->hijos->isNotEmpty() ? $detalle->hijos : collect([$detalle]) as $hoja)
                    @php($avance = $progreso[$hoja->id] ?? ['lista' => '0', 'iniciada' => '0', 'entregable' => '0'])
                    <div wire:key="entrega-{{ $hoja->id }}" class="space-y-2 {{ $detalle->hijos->isNotEmpty() ? 'border-l-2 border-zinc-200 pl-3 dark:border-zinc-700' : '' }}">
                        @if($detalle->hijos->isNotEmpty())<p class="text-sm">{{ $hoja->descripcion }} × {{ \App\Support\Cantidad::mostrar($hoja->cantidad) }}</p>@endif
                        <p class="text-xs text-zinc-500">{{ $hoja->area_preparacion_id ? 'Listos' : 'Entrega directa' }}: {{ \App\Support\Cantidad::mostrar($avance['entregable']) }} · Entregados: {{ \App\Support\Cantidad::mostrar($hoja->cantidad_entregada) }}</p>
                        @if(!in_array($pedido->estado->value, ['cerrado','anulado'], true) && bccomp($avance['entregable'], '0', 4) > 0)
                            @can('entregas.confirmar')
                                <div class="flex items-end gap-2"><div class="min-w-0 flex-1"><flux:input wire:model="cantidadesEntrega.{{ $hoja->id }}" label="Cantidad a entregar" placeholder="1" type="number" min="0.0001" step="0.0001" size="sm" /></div><flux:button size="sm" wire:click="requestDelivery({{ $hoja->id }})">Entregar</flux:button></div>
                            @endcan
                        @endif
                    </div>
                @endforeach
                @if(!in_array($pedido->estado->value, ['cerrado','anulado'], true) && bccomp(bcsub($detalle->cantidad,$detalle->cantidad_anulada,4), '0', 4) > 0)
                    <div class="flex flex-wrap gap-2">
                        @if(bccomp($detalle->cantidad_cobrada,'0',4) === 0)@canany(['descuentos.aplicar','cortesias.aplicar'])<flux:button size="sm" wire:click="openBenefit({{ $detalle->id }})">Descuento / cortesía</flux:button>@endcanany @endif
                        @if(bccomp(bcsub(bcsub($detalle->cantidad,$detalle->cantidad_anulada,4), bccomp($detalle->cantidad_cobrada,$detalle->cantidad_entregada,4)>0?$detalle->cantidad_cobrada:$detalle->cantidad_entregada,4),'0',4)>0)@can('pedidos.anular_enviados')<flux:button size="sm" variant="ghost" wire:click="openCancel({{ $detalle->id }})">Anular producto</flux:button>@endcan @endif
                    </div>
                @endif
            @endif
        </div>
    @endforeach
</div>
