@props(['estado', 'size' => 'sm'])
<span {{ $attributes->class('inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium') }} style="{{ app(\App\Services\Configuracion\IdentidadVisual::class)->estiloEstado((string) $estado) }}">{{ $slot }}</span>
