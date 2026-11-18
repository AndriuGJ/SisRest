@if ($alerta = session()->pull('alerta'))
    <span hidden data-app-alert="{{ json_encode($alerta, JSON_THROW_ON_ERROR) }}"></span>
@endif
