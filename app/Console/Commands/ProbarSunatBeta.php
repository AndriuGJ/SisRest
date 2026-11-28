<?php

namespace App\Console\Commands;

use App\Enums\EntornoConfiguracionFacturacion;
use App\Models\ConfiguracionFacturacion;
use App\Services\Facturacion\CertificadoFiscal;
use App\Services\Facturacion\DocumentoPruebaBeta;
use App\Services\Facturacion\FalloSunat;
use App\Services\Facturacion\LeerCdr;
use App\Services\Facturacion\ReglasFiscales;
use App\Services\Facturacion\TransporteSunat;
use App\Services\Facturacion\XmlFiscal;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Explicit diagnostic traffic only. Never reads the real fiscal configuration or database. */
class ProbarSunatBeta extends Command
{
    protected $signature = 'sunat:probar {tipo? : factura, boleta, resumen o ticket} {--enviar : Autoriza tráfico exclusivamente a Beta} {--certificado= : PEM/P12/PFX exclusivo de pruebas} {--ruc=20000000001 : RUC emisor de pruebas} {--serie= : Serie de pruebas FTS1/BTS1} {--archivo= : Ruta relativa del intento de prueba para consultar su ticket}';

    protected $aliases = ['sunat:test-factura', 'sunat:test-boleta', 'sunat:test-resumen', 'sunat:test-ticket'];

    protected $description = 'Genera pruebas aisladas de SUNAT Beta; no escribe ventas, cajas ni correlativos comerciales';

    public function handle(): int
    {
        $tipo = $this->argument('tipo') ?? str_replace('sunat:test-', '', (string) $this->input->getFirstArgument());
        if (! in_array($tipo, ['factura', 'boleta', 'resumen', 'ticket'], true)) {
            $this->error('Indica factura, boleta, resumen o ticket. Nunca se utiliza Producción.');

            return self::FAILURE;
        }
        try {
            // An unsaved model: no real credentials, certificates or environment settings are read.
            $config = new ConfiguracionFacturacion;
            $config->entorno = EntornoConfiguracionFacturacion::BETA;
            $config->usuario_sol = (string) (getenv('SUNAT_TEST_USUARIO_SOL') ?: 'MODDATOS');
            $config->clave_sol = (string) (getenv('SUNAT_TEST_CLAVE_SOL') ?: 'moddatos');
            $ruc = (string) $this->option('ruc');
            ReglasFiscales::exigir(preg_match('/^\d{11}$/D', $ruc) === 1, 'RUC de pruebas inválido.');
            if ($tipo === 'ticket') {
                return $this->consultar($config, $ruc);
            }
            $path = (string) ($this->option('certificado') ?: getenv('SUNAT_TEST_CERTIFICADO'));
            ReglasFiscales::exigir($path !== '' && is_file($path) && filesize($path) <= 1024 * 1024, 'Indica un certificado exclusivo de pruebas mediante --certificado o SUNAT_TEST_CERTIFICADO.');
            $pem = app(CertificadoFiscal::class)->pem((string) file_get_contents($path), (string) (getenv('SUNAT_TEST_CLAVE_CERTIFICADO') ?: ''));
            $serie = (string) ($this->option('serie') ?: ($tipo === 'factura' ? 'FTS1' : ($tipo === 'resumen' ? 'BTR1' : 'BTS1')));
            ReglasFiscales::exigir(preg_match($tipo === 'factura' ? '/^F[A-Z0-9]{3}$/D' : '/^B[A-Z0-9]{3}$/D', $serie) === 1, 'Serie de pruebas inválida. Usa una serie exclusiva de estas pruebas.');
            $disco = Storage::disk('local');
            $dir = 'sunat-pruebas-beta/'.$ruc;
            $disco->makeDirectory($dir);
            $lock = fopen($disco->path($dir.'/secuencia.lock'), 'c+');
            ReglasFiscales::exigir($lock !== false, 'No se pudo reservar la secuencia de pruebas.');
            try {
                ReglasFiscales::exigir(flock($lock, LOCK_EX), 'No se pudo bloquear la secuencia de pruebas.');
                $fecha = CarbonImmutable::now('America/Lima');
                $clave = $tipo === 'resumen' ? 'RC-'.$fecha->format('Ymd') : $serie;
                $secuencia = $dir.'/'.$clave.'.json';
                $ultimo = $disco->exists($secuencia) ? (int) json_decode($disco->get($secuencia), true, flags: JSON_THROW_ON_ERROR)['ultimo'] : 0;
                ReglasFiscales::exigir($ultimo < ($tipo === 'resumen' ? 99999 : 99999999), 'Secuencia de pruebas agotada.');
                $numero = $ultimo + 1;
                $serieRuta = $dir.'/'.$serie.'.json';
                $numeroBoleta = $tipo === 'resumen' && $disco->exists($serieRuta) ? (int) json_decode($disco->get($serieRuta), true, flags: JSON_THROW_ON_ERROR)['ultimo'] + 1 : $numero;
                ReglasFiscales::exigir($numeroBoleta <= 99999999, 'Secuencia de boletas de prueba agotada.');
                $c = app(DocumentoPruebaBeta::class)->crear($tipo === 'factura' ? '01' : '03', $serie, $numeroBoleta, $ruc);
                $xmlService = app(XmlFiscal::class);
                $documento = $tipo === 'resumen' ? $xmlService->resumenAgrupado([$c], $numero, $fecha) : $xmlService->factura($c);
                $xml = $xmlService->firmar($documento, $pem, $tipo === 'resumen' ? [$c] : null);
                $nombre = $documento->getName();
                $ruta = $dir.'/'.$nombre;
                ReglasFiscales::exigir(! $disco->exists($ruta.'.json') && $disco->put($ruta.'.xml', $xml) === true, 'No se puede sobrescribir un documento de prueba.');
                $registro = ['boleta_referencia' => $tipo === 'resumen' ? XmlFiscal::numeroFiscal($c) : null, 'entorno' => 'beta', 'ruc' => $ruc, 'tipo' => $tipo, 'nombre' => $nombre, 'xml_sha256' => hash('sha256', $xml), 'estado' => 'firmado', 'ticket' => null];
                ReglasFiscales::exigir($disco->put($secuencia, json_encode(['ultimo' => $numero], JSON_THROW_ON_ERROR)) === true && $disco->put($ruta.'.json', json_encode($registro, JSON_THROW_ON_ERROR)) === true, 'No se pudo guardar la reserva de pruebas.');
                if ($tipo === 'resumen') {
                    ReglasFiscales::exigir($disco->put($serieRuta, json_encode(['ultimo' => $numeroBoleta], JSON_THROW_ON_ERROR)) === true, 'No se pudo reservar la boleta de prueba del resumen.');
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $this->info('BETA · Prueba aislada sin validez tributaria: '.$nombre);
            $this->line('XML firmado: '.$disco->path($ruta.'.xml'));
            if (! $this->option('enviar')) {
                $this->warn('Solo generación local. Agrega --enviar para una nueva prueba con tráfico Beta; no se reutilizan números.');

                return self::SUCCESS;
            }
            // Persist uncertainty BEFORE the call. This command never resends a stored XML.
            $registro['estado'] = 'resultado_incierto';
            ReglasFiscales::exigir($disco->put($ruta.'.json', json_encode($registro, JSON_THROW_ON_ERROR)) === true, 'No se pudo reservar el intento de envío.');
            try {
                $respuesta = app(TransporteSunat::class)->enviar($config, $ruc, $tipo === 'factura' ? '01' : '03', $nombre, $xml);
                if ($tipo === 'resumen') {
                    ReglasFiscales::exigir($respuesta->ticket !== null && $respuesta->ticket !== '', 'No se recibió ticket verificable.');
                    $registro['ticket'] = $respuesta->ticket;
                    $registro['estado'] = 'enviado';
                    $this->info('Ticket conservado. Pendiente de CDR; no es una aceptación.');
                } else {
                    $this->cdr($registro, $ruta, $respuesta->cdr);
                }
            } catch (Throwable $error) {
                $registro['diagnostico'] = FalloSunat::desde($error)->seguro();
                $this->error($registro['diagnostico']['mensaje']);
            }
            ReglasFiscales::exigir($disco->put($ruta.'.json', json_encode($registro, JSON_THROW_ON_ERROR)) === true, 'No se pudo guardar el resultado local.');
            $this->line('Registro privado: '.$ruta.'.json');

            return in_array($registro['estado'], ['aceptado', 'observado', 'enviado'], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            $this->error($error instanceof ValidationException ? $error->validator->errors()->first() : FalloSunat::desde($error)->getMessage());

            return self::FAILURE;
        }
    }

    private function consultar(#[\SensitiveParameter] ConfiguracionFacturacion $config, string $ruc): int
    {
        $ruta = (string) $this->option('archivo');
        ReglasFiscales::exigir(preg_match('~^sunat-pruebas-beta/'.preg_quote($ruc, '~').'/'.preg_quote($ruc, '~').'-RC-\d{8}-[1-9]\d{0,4}\.json$~D', $ruta) === 1, 'Selecciona el registro privado de un resumen de prueba, no un comprobante comercial.');
        $disco = Storage::disk('local');
        $r = json_decode($disco->get($ruta), true, flags: JSON_THROW_ON_ERROR);
        ReglasFiscales::exigir($r['entorno'] === 'beta' && $r['ruc'] === $ruc && $r['tipo'] === 'resumen' && is_string($r['ticket']) && $r['ticket'] !== '' && hash_equals($r['xml_sha256'], hash('sha256', $disco->get(substr($ruta, 0, -5).'.xml'))), 'Registro de ticket o XML de pruebas inválido.');
        if (in_array($r['estado'], ['aceptado', 'observado', 'rechazado'], true)) {
            $this->info('El resumen de prueba ya tiene un resultado definitivo.');

            return self::SUCCESS;
        }
        if (! $this->option('enviar')) {
            $this->info('Ticket presente; no se consultó. Agrega --enviar para autorizar la consulta Beta.');

            return self::SUCCESS;
        }
        try {
            $respuesta = app(TransporteSunat::class)->consultarTicket($config, $ruc, $r['ticket'], 'beta');
            if ($respuesta->cdr !== null) {
                $this->cdr($r, substr($ruta, 0, -5), $respuesta->cdr);
            } else {
                $this->warn('Consulta pendiente sin CDR. Se conserva el ticket; no se reenvía el resumen.');
            }
        } catch (Throwable $error) {
            $r['consultas'][] = FalloSunat::desde($error)->seguro();
            $this->warn(FalloSunat::desde($error)->getMessage());
        }
        ReglasFiscales::exigir($disco->put($ruta, json_encode($r, JSON_THROW_ON_ERROR)) === true, 'No se pudo conservar la consulta.');

        return in_array($r['estado'], ['aceptado', 'observado'], true) ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string,mixed> $registro */
    private function cdr(array &$registro, string $ruta, #[\SensitiveParameter] ?string $zip): void
    {
        ReglasFiscales::exigir($zip !== null && strlen($zip) <= 2 * 1024 * 1024, 'No se recibió CDR verificable.');
        $archivo = $ruta.'-cdr-'.Str::uuid().'.zip';
        ReglasFiscales::exigir(Storage::disk('local')->put($archivo, $zip) === true, 'No se pudo conservar el CDR.');
        $registro['cdr_recibidos'][] = ['ruta' => $archivo, 'sha256' => hash('sha256', $zip)];
        $cdr = app(LeerCdr::class)->leer($zip, $registro['nombre'], $registro['ruc']);
        ReglasFiscales::exigir($cdr['codigo'] === '0' || (int) $cdr['codigo'] >= 2000, 'El CDR no expresa un resultado definitivo.');
        $registro['resultado'] = $cdr;
        $registro['estado'] = $cdr['codigo'] === '0' ? ($cdr['observaciones'] ? 'observado' : 'aceptado') : 'rechazado';
        $this->info('Código CDR: '.$cdr['codigo'].' · '.$registro['estado']);
        $this->line($cdr['mensaje']);
    }
}
