<div class="space-y-3" wire:poll.5s>
    @foreach($pedido->detalles->whereNull('detalle_padre_id')->filter(fn($d) => $d->estado->value !== 'borrador') as $detalle)
        <div wire:key="confirmado-{{ $detalle->id }}" class="space-y-3 rounded-md border border-zinc-200 p-3 text-sm dark:border-zinc-700">
            <div><p class="font-medium">{{ $detalle->descripcion }} × {{ \App\Support\Cantidad::mostrar($detalle->cantidad) }}</p><p class="mt-1 text-xs text-zinc-500">{{ ucfirst($detalle->estado->value) }} · S/ {{ $detalle->precio_unitario }} por unidad</p>@if($detalle->nota)<p class="mt-1 text-xs">{{ $detalle->nota }}</p>@endif</div>
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
            @endif
        </div>
    @endforeach
</div>
