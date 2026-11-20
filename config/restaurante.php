<?php

return [
    'moneda' => 'PEN',
    'zona_horaria' => 'America/Lima',
    'ancho_ticket_mm' => 80,

    // Provision the initial account through environment variables; never a shared password.
    'administrador' => [
        'usuario' => env('ADMIN_USERNAME', 'admin'),
        'nombre' => env('ADMIN_NAME', 'Administrador'),
        'correo' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    // Paths are relative to the private local disk. Electronic invoicing starts disabled.
    'facturacion' => [
        'disco' => 'local',
        'directorio' => 'comprobantes',
    ],
];
