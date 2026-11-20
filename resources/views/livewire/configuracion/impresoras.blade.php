<div class="mx-auto w-full max-w-7xl space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mb-1 text-xs font-medium tracking-wide text-zinc-500">CONFIGURACIÓN DEL RESTAURANTE</p>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Impresoras</h1>
            <p class="mt-1 text-sm text-zinc-500">Configura la impresión por red y asigna las impresoras a cajas o preparación.</p>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="newRecord">Nueva impresora</flux:button>
    </header>
    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid items-end gap-3 border-b border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(12rem,1fr)_12rem_11rem_auto] dark:border-zinc-700">
            <flux:input wire:model.live.debounce.300ms="search" label="Buscar impresora" type="search" icon="magnifying-glass" placeholder="Nombre, código o dirección IP" maxlength="100" />
            <flux:select wire:model.live="usageFilter" label="Uso"><option value="">Todos los usos</option><option value="caja">Caja</option><option value="preparacion">Preparación</option><option value="general">General</option></flux:select>
            <flux:select wire:model.live="stateFilter" label="Estado"><option value="">Todos los estados</option><option value="activo">Activas</option><option value="inactivo">Inactivas</option></flux:select>
            <flux:button wire:click="clearFilters" variant="ghost">Limpiar</flux:button>
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,usageFilter,stateFilter,gotoPage,nextPage,previousPage">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Impresoras del restaurante</caption>
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60"><tr><th class="px-4 py-3 font-medium">Impresora</th><th class="px-4 py-3 font-medium">Conexión</th><th class="px-4 py-3 font-medium">Asignación</th><th class="px-4 py-3 font-medium">Estado</th><th class="px-4 py-3 text-right font-medium">Acciones</th></tr></thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($impresoras as $impresora)
                        <tr wire:key="impresora-{{ $impresora->id }}">
                            <td class="px-4 py-3"><p class="font-medium text-zinc-900 dark:text-white">{{ $impresora->nombre }}</p><p class="mt-0.5 text-xs text-zinc-500">{{ $impresora->codigo }} · {{ $impresora->ancho_mm }} mm</p></td>
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-500">{{ $impresora->host ? $impresora->host.':'.$impresora->puerto : 'Sin configurar' }}</td>
                            <td class="px-4 py-3">
                                @if($impresora->cajas->isNotEmpty())
                                    {{ $impresora->cajas->pluck('nombre')->join(', ') }}
                                @elseif($impresora->areaPreparacion)
                                    {{ $impresora->areaPreparacion->nombre }}
                                    @unless($impresora->areaPreparacion->activo)<p class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">Destino inactivo</p>@endunless
                                @else
                                    <span class="text-zinc-500">General</span>
                                @endif
                            </td>
                            <td class="px-4 py-3"><x-estado :estado="$impresora->activo ? 'activo' : 'inactivo'" size="sm">{{ $impresora->activo ? 'Activa' : 'Inactiva' }}</x-estado></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <flux:button size="sm" variant="ghost" icon="printer" wire:click="requestTest({{ $impresora->id }})" :disabled="! $impresora->activo || ! $impresora->host || ($impresora->areaPreparacion && ! $impresora->areaPreparacion->activo)" wire:loading.attr="disabled" wire:target="confirmTest" aria-label="Probar impresora {{ $impresora->nombre }}" title="Imprimir ticket de prueba" />
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editRecord({{ $impresora->id }})" aria-label="Editar impresora {{ $impresora->nombre }}" title="Editar" />
                                <flux:button size="sm" variant="ghost" :icon="$impresora->activo ? 'no-symbol' : 'check-circle'" wire:click="requestStateChange({{ $impresora->id }})" aria-label="{{ $impresora->activo ? 'Desactivar' : 'Activar' }} impresora {{ $impresora->nombre }}" :title="$impresora->activo ? 'Desactivar' : 'Activar'" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-zinc-500">No se encontraron impresoras. Registra una o ajusta los filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700"><span>{{ $impresoras->total() }} resultados</span>{{ $impresoras->links() }}</div>
    </section>
    <p class="text-xs text-zinc-500">La conexión sale desde el servidor donde está instalado SisRest. El botón de prueba envía un ticket físico; comprueba el papel antes de repetirlo.</p>
    @if($pruebas->isNotEmpty())
        <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="border-b border-zinc-200 px-4 py-3 text-sm font-semibold dark:border-zinc-700">Últimas pruebas de impresión</h2>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <caption class="sr-only">Diez últimas pruebas de impresión</caption>
                <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800/60"><tr><th class="px-4 py-3 font-medium">Fecha</th><th class="px-4 py-3 font-medium">Impresora</th><th class="px-4 py-3 font-medium">Resultado</th></tr></thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($pruebas as $prueba)
                        @php($sinConfirmar = $prueba->estado->value === 'resultado_incierto' || ($prueba->estado->value === 'procesando' && $prueba->updated_at->lt(now()->subMinute())))
                        <tr wire:key="prueba-{{ $prueba->id }}">
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-zinc-500">{{ $prueba->created_at->timezone('America/Lima')->format('d/m/Y H:i:s') }}</td>
                            <td class="px-4 py-3"><p>{{ $prueba->configuracion['nombre'] }}</p><p class="mt-0.5 text-xs text-zinc-500">{{ $prueba->configuracion['host'] }}:{{ $prueba->configuracion['puerto'] }}</p></td>
                            <td class="max-w-lg px-4 py-3"><p @class(['text-sm', 'text-amber-700 dark:text-amber-400' => $sinConfirmar, 'text-zinc-600 dark:text-zinc-300' => ! $sinConfirmar])>{{ $sinConfirmar ? 'Resultado sin confirmar. Revisa el papel antes de repetir la prueba.' : $prueba->mensaje }}</p></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>
    @endif
    <flux:modal wire:model.self="showForm" name="impresora-formulario" class="w-full md:min-w-[34rem]" :dismissible="false">
        <form wire:submit="save" class="space-y-5">
            <div class="pr-6"><flux:heading size="lg">{{ $impresoraId ? 'Editar impresora' : 'Nueva impresora' }}</flux:heading><flux:text class="mt-1">Configura una impresora térmica compatible con ESC/POS por red.</flux:text></div>
            <flux:error name="seguridad" />
            <div class="grid items-start gap-4 sm:grid-cols-2">
                <flux:input wire:model="nombre" label="Nombre de la impresora" placeholder="Ej. Impresora Cocina" maxlength="100" required />
                <flux:input wire:model="codigo" label="Código" placeholder="Ej. cocina" maxlength="30" :readonly="$impresoraId !== null" required />
            </div>
            <div class="grid items-start gap-4 sm:grid-cols-[1fr_7rem_8rem]">
                <flux:input wire:model="host" label="Dirección IP" placeholder="192.168.1.100" maxlength="15" />
                <flux:input wire:model="puerto" label="Puerto" type="number" min="1" max="65535" step="1" required />
                <flux:select wire:model="ancho" label="Ancho del papel" required><option value="80">80 mm</option><option value="58">58 mm</option></flux:select>
            </div>
            <flux:select wire:model.live="uso" label="Uso de la impresora" required><option value="general">General</option><option value="caja">Caja</option><option value="preparacion">Preparación</option></flux:select>
            @if($uso === 'caja')
                <div wire:key="impresora-asignacion-caja"><flux:select wire:model="cajaId" label="Caja asignada" required><option value="">Seleccionar caja</option>@foreach($cajas as $caja)<option value="{{ $caja->id }}">{{ $caja->nombre }}{{ $caja->impresora_id && $caja->impresora_id !== $impresoraId ? ' (sustituye '.$caja->impresora->nombre.')' : '' }}</option>@endforeach</flux:select></div>
                <p class="text-xs text-zinc-500">Al guardar se sustituye la asignación anterior de esa caja. Cada caja conserva una impresora propia.</p>
            @elseif($uso === 'preparacion')
                <div wire:key="impresora-asignacion-destino"><flux:select wire:model="destinoId" label="Destino de preparación" required><option value="">Seleccionar destino</option>@foreach($destinos as $destino)<option value="{{ $destino->id }}">{{ $destino->nombre }}{{ $destino->activo ? '' : ' (inactivo)' }}</option>@endforeach</flux:select></div>
                <p class="text-xs text-zinc-500">Se admite una impresora activa por destino para evitar comandas duplicadas.</p>
            @else
                <p class="text-xs text-zinc-500">Disponible para una futura selección manual de impresión, sin asignación fija.</p>
            @endif
            <flux:checkbox wire:model="activo" label="Impresora activa" description="Requiere una dirección IP válida. Guardar no envía ninguna impresión." />
            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700"><flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Guardar impresora</flux:button></div>
        </form>
    </flux:modal>
</div>
