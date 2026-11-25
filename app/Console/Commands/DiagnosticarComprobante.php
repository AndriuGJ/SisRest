<?php

namespace App\Console\Commands;

use App\Models\Comprobante;
use App\Models\User;
use App\Services\Facturacion\AccesoFacturacion;
use App\Services\Facturacion\FalloSunat;
use App\Services\Facturacion\LeerCdr;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Offline, read-only inspection. This command never resolves a transport client. */
class DiagnosticarComprobante extends Command
{
    protected $signature = 'facturacion:diagnosticar {numero : Serie y correlativo, por ejemplo F001-1} {--entorno= : beta o produccion (obligatorio)} {--usuario= : ID de un usuario autorizado (obligatorio)}';

    protected $description = 'Inspecciona el intento y sus archivos locales sin enviar ni consultar SUNAT';

    public function handle(): int
    {
        $entorno = $this->option('entorno');
        if (! in_array($entorno, ['beta', 'produccion'], true) || ! preg_match('/^([FB][A-Z0-9]{3})-([1-9]\d{0,7})$/D', (string) $this->argument('numero'), $m) || ! ctype_digit((string) $this->option('usuario'))) {
            $this->error('Indique número válido, --entorno=beta|produccion y --usuario=ID autorizado.');

            return self::FAILURE;
        }
        $c = Comprobante::where('entorno', $entorno)->where('correlativo', $m[2])->whereHas('serie', fn ($q) => $q->where('serie', $m[1]))->first();
        $actor = User::find((int) $this->option('usuario'));
        if (! $c || ! $actor) {
            $this->error('Documento o usuario no encontrado.');

            return self::FAILURE;
        }
        try {
            AccesoFacturacion::cobro($actor, $c->cobro()->firstOrFail());
        } catch (Throwable) {
            $this->error('El usuario no está autorizado para revisar este comprobante.');

            return self::FAILURE;
        }
        $this->info('Revisión local de solo lectura. No se contactará a SUNAT.');
        $this->line('Entorno: '.$entorno.' · Estado: '.$c->estado_sunat->value);
        foreach ($c->envios()->orderBy('intento')->get() as $envio) {
            $this->line('Intento '.$envio->intento.' · '.$envio->estado->value.' · '.$envio->iniciado_at?->toIso8601String());
            foreach (['diagnostico_envio', 'diagnostico_consulta'] as $campo) {
                $d = $envio->getAttribute($campo);
                $this->line($campo.': '.($d ? json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'sin diagnóstico registrado'));
            }
            if ($envio->diagnostico_envio === null && $envio->estado->value === 'resultado_incierto') {
                $this->warn('El error técnico original no fue conservado: intento anterior o interrumpido sin diagnóstico. No se puede reconstruir con certeza.');
            }
            try {
                $integro = $envio->xml_ruta && Storage::disk('local')->exists($envio->xml_ruta) && hash_equals($envio->xml_sha256 ?? '', hash('sha256', Storage::disk('local')->get($envio->xml_ruta)));
                $this->line('XML enviado: '.($integro ? 'presente; SHA-256 coincide' : 'ausente o SHA-256 no coincide'));
                if ($envio->cdr_recibido_ruta && $envio->cdr_recibido_sha256) {
                    $contenido = Storage::disk('local')->get($envio->cdr_recibido_ruta);
                    if (! hash_equals($envio->cdr_recibido_sha256, hash('sha256', $contenido))) {
                        $this->warn('CDR recibido: SHA-256 no coincide; no se procesará.');

                        continue;
                    }
                    $cdr = app(LeerCdr::class)->leer($contenido, $envio->xml_nombre ?? '', (string) $c->emisor['ruc']);
                    $this->line('CDR local: coincide con el documento; código '.$cdr['codigo'].'. El estado no se ha modificado.');
                } else {
                    $this->line('Sin CDR recibido conservado para revisión local.');
                }
            } catch (Throwable $error) {
                $this->warn('Revisión de archivos: '.FalloSunat::desde($error)->getMessage());
            }
        }
        $this->warn('No reenvíe un resultado incierto. Concilie la evidencia del mismo entorno antes de cualquier regularización.');

        return self::SUCCESS;
    }
}
