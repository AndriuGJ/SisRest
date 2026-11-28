<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>@include('partials.head')</head>
<body class="pos-palette cobro-focus min-h-screen antialiased">
    <main class="cobro-main">{{ $slot }}</main>
    <x-alertas />
    @fluxScripts
</body>
</html>
