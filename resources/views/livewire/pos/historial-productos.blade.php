@if($anulaciones->isNotEmpty() || $beneficiosHistorial->isNotEmpty())
    <details class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"><summary class="cursor-pointer text-sm font-medium">Historial de anulaciones y beneficios</summary>
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div class="space-y-3">@foreach($anulaciones as $anulacion)<div class="border-l-2 border-red-300 pl-3 text-sm"><p class="font-medium">Anulación · {{ $anulacion->usuario_nombre }}</p><p class="mt-1 text-xs text-zinc-500">{{ $anulacion->getAttribute('anulado_at')->format('d/m/Y H:i') }}</p><p class="mt-1">{{ $anulacion->motivo }}</p>@foreach($anulacion->detalles as $linea)<p class="mt-1 text-xs text-zinc-500">{{ $linea->descripcion }} · Liberado: {{ \App\Support\Cantidad::mostrar($linea->cantidad_liberada) }} · Merma: {{ \App\Support\Cantidad::mostrar($linea->cantidad_merma) }}</p>@endforeach</div>@endforeach</div>
            <div class="space-y-3">@foreach($beneficiosHistorial as $registro)<div class="border-l-2 border-green-300 pl-3 text-sm"><p class="font-medium">{{ ucfirst($registro->accion) }} · {{ $registro->usuario_nombre }}</p><p class="mt-1 text-xs text-zinc-500">{{ $pedido->detalles->firstWhere('id',$registro->pedido_detalle_id)?->descripcion }} · {{ $registro->getAttribute('autorizado_at')->format('d/m/Y H:i') }}</p><p class="mt-1">{{ $registro->getAttribute('motivo') }}</p></div>@endforeach</div>
        </div>
        <p class="mt-3 text-xs text-zinc-500">Últimos 20 registros de cada tipo. Los cobros ya confirmados conservan sus propios importes.</p>
    </details>
@endif
