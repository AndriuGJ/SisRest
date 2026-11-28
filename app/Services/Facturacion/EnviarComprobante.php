<?php

namespace App\Services\Facturacion;

use App\Enums\EstadoComprobanteEnvio as EnvioEstado;
use App\Enums\EstadoSunatComprobante as SunatEstado;
use App\Models\Comprobante;
use App\Models\ComprobanteEnvio;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EnviarComprobante
{
    public function enviar(User $actor, int $id): Comprobante
    {
        $salida = DB::transaction(function () use ($actor, $id): ?ComprobanteEnvio {
            $e = Empresa::where('singleton', 1)->lockForUpdate()->firstOrFail();
            $config = ConfiguracionFacturacion::where('empresa_id', $e->id)->lockForUpdate()->firstOrFail();
            $c = Comprobante::lockForUpdate()->findOrFail($id);
            AccesoFacturacion::cobro($actor, $c->cobro()->firstOrFail(), 'comprobantes.emitir');
            $this->validarConfiguracion($e, $config, $c);
            ReglasFiscales::exigir(XmlFiscal::dato($c, 'tipo') !== '03' || ModalidadBoletas::resumen($c) || $config->entorno->value !== 'produccion' || $config->individual_produccion_confirmada, 'No está confirmada la autorización para boletas individuales en producción.');
            if (XmlFiscal::dato($c, 'tipo') === '03' && $c->resumenActivo()->exists()) {
                return null; // A grouped or historical RC already owns this boleta.
            }
            if ($c->envios()->exists()) {
                // Never send again when a previous request might have reached SUNAT.
                return null;
            }
            $hoy = CarbonImmutable::now('America/Lima');
            $tipo = XmlFiscal::dato($c, 'tipo');
            ReglasFiscales::exigir($c->fecha_emision->toDateString() <= $hoy->toDateString() && $c->fecha_emision->addDays($tipo === '03' ? 7 : 3)->toDateString() >= $hoy->toDateString(), 'El comprobante está fuera del plazo de envío. Revisa su regularización tributaria.');
            $xml = $this->xml($c->xml_ruta, XmlFiscal::dato($c, 'xml_sha256'));
            $nombre = XmlFiscal::nombre($c);
            $ruta = $c->xml_ruta;
            if ($tipo === '03' && ModalidadBoletas::resumen($c)) {
                $fecha = $hoy->toDateString();
                $entorno = $config->entorno->value;
                $fila = DB::table('secuencias_resumen')->where(compact('fecha', 'entorno'))->lockForUpdate()->first();
                $numero = ($fila ? (int) $fila->correlativo : 0) + 1;
                ReglasFiscales::exigir($numero <= 99999, 'Se agotó el correlativo de resúmenes de hoy.');
                $resumen = app(XmlFiscal::class)->resumen($c, $numero, $hoy);
                $xml = app(XmlFiscal::class)->firmar($resumen, app(CertificadoFiscal::class)->cargar($config), $c);
                $nombre = $resumen->getName();
                $ruta = 'comprobantes/'.$entorno.'/'.$c->clave_idempotencia.'/'.$nombre.'.xml';
                ReglasFiscales::exigir(Storage::disk('local')->put($ruta, $xml) === true, 'No se pudo guardar el resumen firmado.');
                DB::table('secuencias_resumen')->updateOrInsert(compact('fecha', 'entorno'), ['correlativo' => $numero]);
            }

            return $c->envios()->create(['intento' => 1, 'usuario_id' => $actor->id, 'estado' => 'en_proceso', 'iniciado_at' => now(), 'xml_nombre' => $nombre, 'xml_ruta' => $ruta, 'xml_sha256' => hash('sha256', $xml)]);
        }, 3);
        if ($salida !== null) {
            $c = Comprobante::findOrFail($id);
            $etapa = 'xml_local';
            try {
                $xml = $this->xml($salida->xml_ruta, $salida->xml_sha256 ?? '');
                $etapa = 'transporte';
                $respuesta = app(TransporteSunat::class)->enviar(ConfiguracionFacturacion::firstOrFail(), (string) $c->emisor['ruc'], XmlFiscal::dato($c, 'tipo'), $salida->xml_nombre ?? '', $xml);
                $etapa = 'registro_respuesta';
                $this->registrar($actor, $salida->id, $respuesta, $etapa);
            } catch (Throwable $error) {
                $this->incierto($actor, $salida->id, false, $error, $etapa);
            }
        }

        $this->vincularResumenUnitario($id);

        return Comprobante::findOrFail($id);
    }

    public function consultar(User $actor, int $id): Comprobante
    {
        $leido = Comprobante::findOrFail($id);
        $vinculo = $leido->resumenActivo()->with('resumen')->first();
        if ($vinculo && $vinculo->resumen->envio_historico_id === null) {
            AccesoFacturacion::cobro($actor, $leido->cobro()->firstOrFail());
            app(GestionResumenes::class)->consultar($actor, $vinculo->resumen_diario_id);

            return $leido->fresh();
        }
        $salida = DB::transaction(function () use ($actor, $id): ?ComprobanteEnvio {
            $e = Empresa::where('singleton', 1)->lockForUpdate()->firstOrFail();
            $config = ConfiguracionFacturacion::where('empresa_id', $e->id)->lockForUpdate()->firstOrFail();
            $c = Comprobante::lockForUpdate()->findOrFail($id);
            AccesoFacturacion::cobro($actor, $c->cobro()->firstOrFail());
            $this->validarConfiguracion($e, $config, $c);
            if (in_array($c->estado_sunat, [SunatEstado::ACEPTADO, SunatEstado::OBSERVADO, SunatEstado::RECHAZADO], true)) {
                return null;
            }
            $envio = $c->envios()->lockForUpdate()->orderByDesc('id')->firstOrFail();
            $ocupado = $envio->estado === EnvioEstado::EN_PROCESO || $envio->consultando;
            $inicio = $envio->estado === EnvioEstado::EN_PROCESO ? $envio->iniciado_at : ($envio->consulta_iniciada_at ?? $envio->iniciado_at);
            if ($ocupado && $inicio?->gt(now()->subMinutes(2))) {
                return null;
            }
            ReglasFiscales::exigir(! ModalidadBoletas::resumen($c) ? $config->entorno->value === 'produccion' : $envio->ticket !== null, 'Este resultado requiere conciliación manual: la consulta necesita un ticket de resumen o un comprobante individual de producción.');
            $envio->update(['consultando' => true, 'consulta_iniciada_at' => now(), 'consulta_finalizada_at' => null]);

            return $envio;
        }, 3);
        if ($salida !== null) {
            $etapa = 'transporte';
            try {
                $respuesta = app(TransporteSunat::class)->consultar(ConfiguracionFacturacion::firstOrFail(), Comprobante::findOrFail($id), $salida->ticket);
                $etapa = 'registro_respuesta';
                $this->registrar($actor, $salida->id, $respuesta, $etapa, consulta: true);
            } catch (Throwable $error) {
                $this->incierto($actor, $salida->id, true, $error, $etapa);
            }
        }

        $this->vincularResumenUnitario($id);

        return Comprobante::findOrFail($id);
    }

    /** Reconcile only the stored response. Never invokes the SUNAT transport or signs XML. */
    public function reprocesarCdr(User $actor, int $id, int $envioId): Comprobante
    {
        return DB::transaction(function () use ($actor, $id, $envioId): Comprobante {
            // Same lock order as send/consultation registration; concurrent confirmations serialize.
            $c = Comprobante::lockForUpdate()->findOrFail($id);
            AccesoFacturacion::cobro($actor, $c->cobro()->firstOrFail(), 'comprobantes.emitir');
            $envio = $c->envios()->whereKey($envioId)->lockForUpdate()->firstOrFail();
            ReglasFiscales::exigir(! ModalidadBoletas::resumen($c) && $c->entorno === XmlFiscal::dato($c, 'entorno') && in_array($c->entorno, ['beta', 'produccion'], true), 'El reprocesamiento local requiere un comprobante individual con entorno consistente.');
            ReglasFiscales::exigir(! $envio->consultando && $envio->estado !== EnvioEstado::EN_PROCESO, 'Hay una operación en curso. Espera a que finalice antes de reprocesar el CDR.');
            ReglasFiscales::exigir($envio->xml_nombre === XmlFiscal::nombre($c), 'El intento no corresponde al comprobante.');
            $contenido = $this->cdrAlmacenado($c, $envio);
            $cdr = app(LeerCdr::class)->leer($contenido, $envio->xml_nombre, (string) $c->emisor['ruc']);
            $estado = $this->estadoCdr($cdr);
            ReglasFiscales::exigir($estado !== 'desconocido', 'El CDR no contiene un resultado fiscal definitivo. Se conserva el estado anterior.');
            if (in_array($c->estado_sunat, [SunatEstado::ACEPTADO, SunatEstado::OBSERVADO, SunatEstado::RECHAZADO], true)) {
                ReglasFiscales::exigir($c->estado_sunat->value === $estado && $envio->estado->value === $estado && $c->cdr_ruta === $envio->cdr_recibido_ruta && $envio->cdr_ruta === $envio->cdr_recibido_ruta, 'El comprobante ya tiene un resultado definitivo diferente. Se requiere conciliación.');

                return $c; // Idempotent: no second audit, message append or timestamp update.
            }
            ReglasFiscales::exigir($c->estado_sunat === SunatEstado::DESCONOCIDO && $envio->estado === EnvioEstado::RESULTADO_INCIERTO, 'Solo se recuperan intentos con resultado incierto.');
            $anterior = $c->estado_sunat->value;
            $c->update(['estado_sunat' => $estado, 'cdr_ruta' => $envio->cdr_recibido_ruta]);
            $envio->update([
                'estado' => $estado,
                'codigo_respuesta' => $cdr['codigo'],
                'cdr_ruta' => $envio->cdr_recibido_ruta,
                // Preserve the original attempt message, diagnostics and send timestamps.
                'mensaje' => ($envio->mensaje ? $envio->mensaje."\n\n" : '').'CDR reprocesado localmente: '.$cdr['mensaje'].($cdr['observaciones'] ? "\n".implode("\n", $cdr['observaciones']) : ''),
            ]);
            activity()->causedBy($actor)->performedOn($c)->withProperties([
                'origen' => 'cdr_local', 'envio_id' => $envio->id,
                'estado_anterior' => $anterior, 'estado_sunat' => $estado,
                'codigo_respuesta' => $cdr['codigo'], 'cdr_sha256' => $envio->cdr_recibido_sha256,
            ])->log('CDR almacenado reprocesado localmente');

            return $c;
        }, 3);
    }

    private function cdrAlmacenado(Comprobante $c, ComprobanteEnvio $envio): string
    {
        $ruta = $envio->cdr_recibido_ruta;
        $hash = $envio->cdr_recibido_sha256;
        $prefijo = 'comprobantes/'.$c->entorno.'/'.$c->clave_idempotencia.'/';
        ReglasFiscales::exigir(is_string($ruta) && str_starts_with($ruta, $prefijo) && ! str_contains($ruta, '\\') && ! array_intersect(explode('/', $ruta), ['.', '..']) && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1, 'No hay una ruta y SHA-256 válidos del CDR recibido.');
        try {
            $disco = Storage::disk('local');
            ReglasFiscales::exigir($disco->exists($ruta), 'No se encontró el ZIP del CDR almacenado.');
            $stream = $disco->readStream($ruta);
            ReglasFiscales::exigir(is_resource($stream), 'No se pudo leer el ZIP del CDR almacenado.');
            try {
                $contenido = stream_get_contents($stream, 2 * 1024 * 1024 + 1);
            } finally {
                fclose($stream);
            }
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable) {
            throw ValidationException::withMessages(['facturacion' => 'No se pudo leer el ZIP del CDR almacenado.']);
        }
        ReglasFiscales::exigir(is_string($contenido) && strlen($contenido) > 0 && strlen($contenido) <= 2 * 1024 * 1024, 'El tamaño del CDR almacenado no es válido.');
        ReglasFiscales::exigir(hash_equals($hash, hash('sha256', $contenido)), 'El SHA-256 del CDR no coincide. El archivo pudo ser alterado; no se modificó el comprobante.');

        return $contenido;
    }

    /** @param array{codigo:string,mensaje:string,observaciones:list<string>} $cdr */
    private function estadoCdr(array $cdr): string
    {
        return $cdr['codigo'] === '0' ? ($cdr['observaciones'] ? 'observado' : 'aceptado') : ((int) $cdr['codigo'] >= 2000 ? 'rechazado' : 'desconocido');
    }

    public function recuperarInterrumpido(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $c = Comprobante::lockForUpdate()->findOrFail($id);
            AccesoFacturacion::cobro($actor, $c->cobro()->firstOrFail());
            $envio = $c->envios()->lockForUpdate()->orderByDesc('id')->first();
            if (! $envio) {
                return;
            }
            $inicio = $envio->estado === EnvioEstado::EN_PROCESO ? $envio->iniciado_at : ($envio->consulta_iniciada_at ?? $envio->iniciado_at);
            if (! $inicio?->lte(now()->subMinutes(2))) {
                return;
            }
            if ($envio->estado === EnvioEstado::EN_PROCESO) {
                $envio->update(['estado' => 'resultado_incierto', 'consultando' => false, 'finalizado_at' => now(), 'mensaje' => 'El envío se interrumpió sin respuesta verificable. Consulta o concilia el resultado antes de repetirlo.']);
                $c->update(['estado_sunat' => 'desconocido']);
            } elseif ($envio->consultando) {
                $envio->update(['consultando' => false, 'consulta_finalizada_at' => now()]);
            }
        }, 3);
    }

    private function vincularResumenUnitario(int $id): void
    {
        $c = Comprobante::findOrFail($id);
        if (XmlFiscal::dato($c, 'tipo') === '03') {
            $envio = $c->envios()->latest('id')->first();
            if ($envio) {
                app(ImportarResumenesHistoricos::class)->sincronizar($envio->id);
            }
        }
    }

    private function validarConfiguracion(Empresa $e, ConfiguracionFacturacion $config, Comprobante $c): void
    {
        ReglasFiscales::exigir($config->habilitada && $config->entorno->value === XmlFiscal::dato($c, 'entorno') && $e->ruc === ($c->emisor['ruc'] ?? null) && $config->usuario_sol !== null && $config->clave_sol !== null, 'El emisor o entorno actual no corresponde al comprobante, o la facturación está deshabilitada.');
    }

    private function xml(?string $ruta, string $hash): string
    {
        ReglasFiscales::exigir($ruta !== null && Storage::disk('local')->exists($ruta), 'No se encontró el XML firmado.');
        $xml = Storage::disk('local')->get($ruta);
        ReglasFiscales::exigir(hash_equals($hash, hash('sha256', $xml)), 'El XML firmado cambió. No se enviará.');

        return $xml;
    }

    private function registrar(User $actor, int $envioId, #[\SensitiveParameter] RespuestaSunat $r, string &$etapa, bool $consulta = false): void
    {
        $envioLeido = ComprobanteEnvio::findOrFail($envioId);
        $c = Comprobante::findOrFail($envioLeido->comprobante_id);
        $cdr = null;
        $ruta = null;
        if ($r->ticket !== null && ! ModalidadBoletas::resumen($c)) {
            throw new FalloSunat('respuesta_invalida');
        }
        if ($r->cdr !== null) {
            $etapa = 'cdr_guardado';
            if (strlen($r->cdr) > 2 * 1024 * 1024) {
                throw new FalloSunat('cdr_tamano');
            }
            // Quarantine before parsing: a malformed response is evidence, never an accepted CDR.
            // Preserve the first captured response; subsequent consultations use distinct files.
            $ruta = 'comprobantes/'.XmlFiscal::dato($c, 'entorno').'/'.$c->clave_idempotencia.'/recibidos/'.Str::uuid().'.zip';
            try {
                if (! Storage::disk('local')->put($ruta, $r->cdr)) {
                    throw new FalloSunat('cdr_guardado');
                }
            } catch (Throwable) {
                throw new FalloSunat('cdr_guardado');
            }
            ComprobanteEnvio::whereKey($envioId)->whereNull('cdr_recibido_ruta')->update(['cdr_recibido_ruta' => $ruta, 'cdr_recibido_sha256' => hash('sha256', $r->cdr)]);
            $etapa = 'cdr_lectura';
            $cdr = app(LeerCdr::class)->leer($r->cdr, $envioLeido->xml_nombre ?? '', (string) $c->emisor['ruc']);
            if ($consulta && $this->estadoCdr($cdr) === 'desconocido') {
                throw new FalloSunat('cdr_no_definitivo');
            }
        }
        $etapa = 'registro_respuesta';
        DB::transaction(function () use ($actor, $envioId, $r, $cdr, $ruta, $consulta): void {
            $leido = ComprobanteEnvio::findOrFail($envioId);
            $c = Comprobante::lockForUpdate()->findOrFail($leido->comprobante_id);
            $envio = ComprobanteEnvio::lockForUpdate()->findOrFail($envioId);
            if (in_array($c->estado_sunat, [SunatEstado::ACEPTADO, SunatEstado::OBSERVADO, SunatEstado::RECHAZADO], true)) {
                return;
            }
            if ($cdr !== null) {
                $estado = $this->estadoCdr($cdr);
                $c->update(['estado_sunat' => $estado, 'cdr_ruta' => $ruta]);
                $envio->update(['estado' => $estado === 'desconocido' ? 'resultado_incierto' : $estado, 'codigo_respuesta' => $cdr['codigo'], 'mensaje' => ($consulta && $envio->mensaje ? $envio->mensaje."\n\nResultado de consulta: " : '').$cdr['mensaje'].($cdr['observaciones'] ? "\n".implode("\n", $cdr['observaciones']) : ''), 'cdr_ruta' => $ruta, 'consultando' => false, ($consulta ? 'consulta_finalizada_at' : 'finalizado_at') => now()]);
            } elseif ($r->ticket !== null) {
                $envio->update(['estado' => 'enviado', 'ticket' => $r->ticket, 'mensaje' => 'Resumen recibido. Consulta el ticket para obtener el CDR.', 'finalizado_at' => now()]);
                $c->update(['estado_sunat' => 'enviado']);
            } elseif ($r->pendiente) {
                $envio->update(['consultando' => false, 'codigo_respuesta' => $r->codigo, 'consulta_finalizada_at' => now()]);
            }
            activity()->causedBy($actor)->performedOn($c)->withProperties(['estado_sunat' => $c->estado_sunat->value, 'envio' => $envioId, 'operacion' => $consulta ? 'consulta' : 'envio', 'codigo_respuesta' => $r->codigo])->log('Respuesta de facturación registrada');
        }, 3);
    }

    private function incierto(User $actor, int $id, bool $consulta, #[\SensitiveParameter] Throwable $error, string $etapa): void
    {
        $fallo = $etapa === 'transporte' || $error instanceof FalloSunat ? FalloSunat::desde($error) : new FalloSunat('error_no_clasificado');
        $diagnostico = $fallo->seguro() + [
            'referencia' => (string) Str::uuid(),
            'etapa' => $etapa,
            'operacion' => $consulta ? 'consulta' : 'envio',
            'fecha' => now()->toIso8601String(),
        ];
        DB::transaction(function () use ($actor, $id, $consulta, $diagnostico): void {
            $leido = ComprobanteEnvio::findOrFail($id);
            $c = Comprobante::lockForUpdate()->findOrFail($leido->comprobante_id);
            $envio = ComprobanteEnvio::lockForUpdate()->findOrFail($id);
            if (in_array($c->estado_sunat, [SunatEstado::ACEPTADO, SunatEstado::OBSERVADO, SunatEstado::RECHAZADO], true)) {
                return;
            }
            $diagnostico += [
                'metodo_soap' => $consulta ? (ModalidadBoletas::resumen($c) ? 'getStatus' : 'getStatusCdr') : (ModalidadBoletas::resumen($c) ? 'sendSummary' : 'sendBill'),
                'servicio' => XmlFiscal::dato($c, 'entorno') === 'beta' ? 'fe_beta' : ($consulta && ! ModalidadBoletas::resumen($c) ? 'consulta_cdr_produccion' : 'fe_produccion'),
            ];
            $campo = $consulta ? 'diagnostico_consulta' : 'diagnostico_envio';
            if ($consulta || $envio->diagnostico_envio === null) {
                $envio->setAttribute($campo, $diagnostico);
            }
            $cambios = ['consultando' => false, ($consulta ? 'consulta_finalizada_at' : 'finalizado_at') => now()];
            if (! $consulta) {
                $cambios += ['estado' => 'resultado_incierto', 'mensaje' => 'No se obtuvo una respuesta verificable. No repitas el envío; consulta o concilia el resultado.'];
            }
            $envio->update($cambios);
            if (! $consulta) {
                $c->update(['estado_sunat' => 'desconocido']);
            }
            if ($consulta) {
                activity()->causedBy($actor)->performedOn($c)->withProperties($diagnostico + ['envio_id' => $id])->log('Consulta SUNAT sin respuesta verificable');
            }
            // Explicit allowlist only. Never report($error), faultstring, detail or SOAP envelopes.
            Log::warning('Diagnóstico seguro SUNAT', $diagnostico + ['envio_id' => $id, 'comprobante_id' => $c->id, 'entorno' => XmlFiscal::dato($c, 'entorno')]);
        }, 3);
    }
}
