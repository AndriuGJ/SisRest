<?php

namespace App\Http\Controllers\Facturacion;

use App\Models\Comprobante;
use App\Models\User;
use App\Services\Facturacion\AccesoFacturacion;
use App\Services\Facturacion\RepresentacionFiscal;
use App\Services\Facturacion\XmlFiscal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ArchivoComprobanteController
{
    public function __invoke(Comprobante $comprobante, string $archivo): Response
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        AccesoFacturacion::cobro($actor, $comprobante->cobro()->firstOrFail());
        if ($archivo === 'pdf') {
            File::ensureDirectoryExists((string) config('dompdf.options.font_cache', storage_path('fonts')));
            $representacion = app(RepresentacionFiscal::class);

            return Pdf::loadView('facturacion.comprobante', ['c' => $comprobante->load('detalles'), 'contenido' => $representacion->contenido($comprobante), 'qr' => $representacion->qrImagen($comprobante)])
                ->setOption('isRemoteEnabled', false)->setPaper('a4')->stream(XmlFiscal::nombre($comprobante).'.pdf')->header('Cache-Control', 'private, no-store');
        }
        $ruta = match ($archivo) {
            'xml' => $comprobante->xml_ruta, 'cdr' => $comprobante->cdr_ruta, 'resumen' => $comprobante->envios()->latest('id')->first()?->xml_ruta, default => abort(404)
        };
        abort_unless($ruta !== null && Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->download($ruta, basename($ruta), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
