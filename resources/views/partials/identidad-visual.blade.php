@php($visual = app(\App\Services\Configuracion\IdentidadVisual::class))
@php($tipografia = $visual->tipografia())
<style>
@foreach(\App\Services\Configuracion\IdentidadVisual::FUENTES as $clave => $nombre)
@font-face{font-family:'{{ $nombre }}';font-style:normal;font-weight:400;src:url('{{ asset('fonts/'.$clave.'-400.ttf') }}') format('truetype');font-display:swap}
@font-face{font-family:'{{ $nombre }}';font-style:normal;font-weight:500 900;src:url('{{ asset('fonts/'.$clave.'-600.ttf') }}') format('truetype');font-display:swap}
@endforeach
:root{--font-sans:'{{ \App\Services\Configuracion\IdentidadVisual::FUENTES[$tipografia['fuente']] }}',ui-sans-serif,system-ui,sans-serif;--app-font-size:{{ $tipografia['tamano'] }}px}
html{font-size:calc(var(--app-font-size) * 8 / 7)}
body{font-family:var(--font-sans)}
</style>
