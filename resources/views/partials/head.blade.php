<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.app(\App\Services\Configuracion\IdentidadVisual::class)->nombre() : app(\App\Services\Configuracion\IdentidadVisual::class)->nombre() }}
</title>

@php($marca = app(\App\Services\Configuracion\IdentidadVisual::class))
@if($marca->logoUrl())
<link rel="icon" href="{{ $marca->logoUrl() }}" type="image/png">
<link rel="apple-touch-icon" href="{{ $marca->logoUrl() }}">
@else
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
@endif

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

@include('partials.identidad-visual')
