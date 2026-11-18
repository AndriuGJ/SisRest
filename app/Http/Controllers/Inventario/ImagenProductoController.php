<?php

namespace App\Http\Controllers\Inventario;

use App\Models\Producto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImagenProductoController
{
    public function __invoke(Producto $producto): BinaryFileResponse
    {
        $ruta = $producto->imagen_ruta;
        abort_unless(is_string($ruta) && preg_match('~^productos/[a-zA-Z0-9_-]+\.(?:jpe?g|png|webp)$~i', $ruta), 404);
        $disco = Storage::disk('public');
        abort_unless($disco->exists($ruta), 404);
        $mime = $disco->mimeType($ruta);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response()->file($disco->path($ruta), ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600']);
    }
}
