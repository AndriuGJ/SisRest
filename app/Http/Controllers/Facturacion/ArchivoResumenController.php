<?php

namespace App\Http\Controllers\Facturacion;

use App\Models\ResumenDiario;
use App\Models\User;
use App\Services\Facturacion\AccesoResumenes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ArchivoResumenController
{
    public function __invoke(ResumenDiario $resumen, string $archivo): Response
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        AccesoResumenes::autorizar($actor, $resumen);
        $ruta = match ($archivo) {
            'xml' => $resumen->xml_ruta, 'cdr' => $resumen->cdr_ruta, default => abort(404)
        };
        abort_unless($ruta !== null && Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->download($ruta, ($archivo === 'cdr' ? 'R-' : '').$resumen->nombre.'.'.($archivo === 'cdr' ? 'zip' : 'xml'), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
