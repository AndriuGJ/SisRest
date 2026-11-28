@php
$identidad = app(\App\Services\Configuracion\IdentidadVisual::class);
$empresaReporte = $identidad->empresa();
$logoReporte = $identidad->logoDatos($empresaReporte?->logo_ruta);
$tipoReporte = $identidad->tipografia();
@endphp
<style>@font-face{font-family:EmpresaReporte;src:url("{{ $identidad->fuenteArchivo($tipoReporte['fuente']) }}")}body{font-family:EmpresaReporte,sans-serif;font-size:{{ $tipoReporte['documentos'] }}pt}</style>
<header style="margin-bottom:18px;border-bottom:1px solid #ddd;padding-bottom:12px">
@if($logoReporte)<img src="{{ $logoReporte }}" alt="Logo" style="max-width:150px;max-height:70px">@endif
<strong>{{ $identidad->nombre() }}</strong>
@if($empresaReporte?->ruc)<p>RUC {{ $empresaReporte->ruc }}</p>@endif
<p>{{ $empresaReporte?->direccion }}</p>
@if(isset($tituloReporte))<h1>{{ $tituloReporte }}</h1>@endif
</header>
