<div class="space-y-5" wire:poll.5s>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div><flux:heading level="1" size="xl">Cocina</flux:heading><flux:text class="mt-1">Productos por envío, en orden de llegada. Actualización cada 5 segundos.</flux:text></div>
        <flux:button icon="arrow-path" wire:click="$refresh">Actualizar</flux:button>
    </div>
    <div class="grid gap-3 sm:max-w-2xl sm:grid-cols-2">
        <flux:select wire:model.live="areaId" label="Destino"><option value="">Todos los destinos</option>@foreach($areas as $area)<option value="{{ $area->id }}">{{ $area->nombre }}</option>@endforeach</flux:select>
        <flux:select wire:model.live="estado" label="Mostrar"><option value="pendientes">Pendientes de preparación</option><option value="listos">Listos para entregar</option><option value="todos">Todos sin entregar</option></flux:select>
    </div>
    <div class="grid items-start gap-4 xl:grid-cols-2 2xl:grid-cols-3">
        @forelse($envios as $envio)
            <section wire:key="envio-{{ $envio->id }}" class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="space-y-2 border-b border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between gap-3">
                        <div><h2 class="font-semibold">{{ $envio->pedido->modalidad->value === 'mesa' ? 'Mesa '.($envio->pedido->mesa_codigo ?? $envio->pedido->mesa?->codigo) : ($envio->pedido->modalidad->value === 'rapida' ? 'Para llevar' : 'Delivery') }}</h2><p class="mt-1 text-xs text-zinc-500">{{ $envio->pedido->salon_nombre }}{{ $envio->pedido->salon_nombre ? ' · ' : '' }}{{ $envio->pedido->mozo_nombre ?? $envio->pedido->mozo?->name }}</p></div>
                        <span class="rounded bg-amber-100 px-2 py-1 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">{{ $envio->area_nombre ?? $envio->areaPreparacion->nombre }}</span>
                    </div>
                    <div class="flex justify-between gap-3 text-xs text-zinc-500"><span>Lote {{ $envio->numero_envio }} · {{ $envio->enviado_at->format('H:i') }}</span><span>{{ (int) $envio->enviado_at->diffInMinutes(now()) }} min</span></div>
                    @if($envio->pedido->observaciones)<p class="text-sm">{{ $envio->pedido->observaciones }}</p>@endif
                </div>
                <div class="divide-y divide-zinc-200 px-4 dark:divide-zinc-700">
                    @foreach($envio->detalles as $detalle)
                        @php
                            $porIniciar = bcsub(bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4), $detalle->cantidad_iniciada, 4);
                            $preparando = bcsub($detalle->cantidad_iniciada, $detalle->cantidad_lista, 4);
                            $lista = bcsub($detalle->cantidad_lista, $detalle->cantidad_entregada, 4);
                            $vigente = bccomp(bcadd($detalle->cantidad_entregada, $detalle->cantidad_anulada, 4), $detalle->cantidad, 4) < 0;
                            $version = \App\Services\Cocina\GestionCocina::huella($detalle);
                        @endphp
                        <div wire:key="cocina-linea-{{ $detalle->id }}" class="space-y-3 py-4 {{ $vigente ? '' : 'opacity-50' }}">
                            <div><p class="text-sm font-semibold">{{ \App\Support\Cantidad::mostrar($detalle->cantidad) }} × {{ $detalle->descripcion }}</p>
                            @if($detalle->detallePedido->padre)<p class="mt-1 text-xs text-zinc-500">Combo: {{ $detalle->detallePedido->padre->descripcion }}</p>@endif
                            @if(bccomp($detalle->cantidad_anulada, '0', 4) > 0)<p class="mt-1 text-xs text-red-700 dark:text-red-300">Anulados: {{ \App\Support\Cantidad::mostrar($detalle->cantidad_anulada) }}</p>@endif
                            @if($detalle->nota)<p class="mt-2 border-l-2 border-amber-500 pl-2 text-sm">{{ $detalle->nota }}</p>@endif</div>
                            <dl class="grid grid-cols-3 gap-2 text-xs"><div><dt class="text-zinc-500">Sin iniciar</dt><dd class="mt-1 font-semibold">{{ \App\Support\Cantidad::mostrar($porIniciar) }}</dd></div><div><dt class="text-zinc-500">Preparando</dt><dd class="mt-1 font-semibold">{{ \App\Support\Cantidad::mostrar($preparando) }}</dd></div><div><dt class="text-zinc-500">Listo</dt><dd class="mt-1 font-semibold text-green-700 dark:text-green-400">{{ \App\Support\Cantidad::mostrar($lista) }}</dd></div></dl>
                            @if($vigente && (bccomp($porIniciar, '0', 4) > 0 || bccomp($preparando, '0', 4) > 0))
                                @canany(['cocina.preparar','cocina.marcar_listo'])
                                    <flux:input wire:model="cantidades.{{ $detalle->id }}" type="number" step="0.0001" min="0.0001" placeholder="1" label="Cantidad a procesar" size="sm" />
                                    <div class="flex flex-wrap gap-2">
                                        @if(bccomp($porIniciar, '0', 4) > 0)@can('cocina.preparar')<flux:button size="sm" wire:click="avanzar({{ $detalle->id }}, 'iniciar', '{{ $version }}')" wire:loading.attr="disabled">Iniciar preparación</flux:button>@endcan @endif
                                        @if(bccomp($preparando, '0', 4) > 0)@can('cocina.marcar_listo')<flux:button size="sm" variant="primary" wire:click="avanzar({{ $detalle->id }}, 'listo', '{{ $version }}')" wire:loading.attr="disabled">Marcar listo</flux:button>@endcan @endif
                                    </div>
                                @endcanany
                            @elseif($vigente)<p class="text-xs text-green-700 dark:text-green-400">Listo para recoger desde el POS.</p>@else<p class="text-xs text-zinc-500">Entregado o anulado.</p>@endif
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <p class="col-span-full rounded-lg border border-zinc-200 py-12 text-center text-sm text-zinc-500 dark:border-zinc-700">No hay envíos con estos filtros.</p>
        @endforelse
    </div>
    {{ $envios->links() }}
</div>
