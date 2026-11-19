<?php

namespace App\Http\Controllers\Configuracion;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class LogoEmpresaController
{
    public function __invoke(string $archivo): Response
    {
        abort_unless((bool) preg_match('/^[a-f0-9-]{36}\.png$/', $archivo), 404);
        $ruta = 'marca/'.$archivo;
        abort_unless(Storage::disk('local')->exists($ruta), 404);

        return response(Storage::disk('local')->get($ruta), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }
}
