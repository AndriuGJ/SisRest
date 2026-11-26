<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\ResumenDiario;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class GestionResumenes
{
    /** @return Builder<Comprobante> */
    public function elegibles(User $actor): Builder
    {
        return AccesoResumenes::comprobantes($actor)->where('configuracion_tributaria->tipo', '03')->where('estado', 'emitido')->where('estado_sunat', 'pendiente')
            ->where('moneda', 'PEN')->whereDoesntHave('envios')->whereDoesntHave('resumenActivo');
    }

    /** @param list<int|string> $ids */
    public function generar(User $actor, array $ids, string $clave): ResumenDiario
    {
        Validator::make(compact('ids', 'clave'), ['clave' => 'required|uuid', 'ids' => 'required|array|min:1|max:500', 'ids.*' => 'required|integer|min:1|distinct'])->validate();
        $ids = array_map(intval(...), $ids);
        sort($ids);

        return DB::transaction(function () use ($actor, $ids, $clave): ResumenDiario {
            $empresa = Empresa::where('singleton', 1)->lockForUpdate()->firstOrFail();
            AccesoFacturacion::usuario($actor, 'comprobantes.emitir');
            if ($previo = ResumenDiario::where('clave_idempotencia', $clave)->first()) {
                AccesoResumenes::autorizar($actor, $previo, 'comprobantes.emitir');
                $anteriores = $previo->detalles()->orderBy('comprobante_id')->pluck('comprobante_id')->all();
                ReglasFiscales::exigir($previo->usuario_id === $actor->id && $anteriores === $ids, 'La solicitud ya corresponde a otra selección de boletas.');

                return $previo;
            }
            $config = ConfiguracionFacturacion::where('empresa_id', $empresa->id)->lockForUpdate()->firstOrFail();
            $boletas = Comprobante::with('detalles')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            ReglasFiscales::exigir($boletas->count() === count($ids), 'Una boleta seleccionada ya no existe.');
            $primera = $boletas->firstOrFail();
            $hoy = CarbonImmutable::now('America/Lima');
            EmitirComprobante::configurada($empresa, $config, $hoy);
            $this->plazo($primera->fecha_emision);
            ReglasFiscales::exigir($this->elegibles($actor)->whereIn('id', $ids)->count() === count($ids), 'Una boleta ya fue vinculada, enviada, no es elegible o no tienes acceso a ella.');
            $total = '0.00';
            foreach ($boletas as $c) {
                ReglasFiscales::exigir($c->entorno === $config->entorno->value && XmlFiscal::dato($c, 'entorno') === $c->entorno && $c->fecha_emision->toDateString() === $primera->fecha_emision->toDateString() && ($c->emisor['ruc'] ?? null) === $empresa->ruc && $c->moneda === $primera->moneda, 'Agrupa boletas de la misma fecha, emisor, moneda y entorno configurado.');
                $this->leerXml($c->xml_ruta, XmlFiscal::dato($c, 'xml_sha256'));
                $total = bcadd($total, $c->total, 2);
            }
            $entorno = $config->entorno->value;
            $fecha = $hoy->toDateString();
            $fila = DB::table('secuencias_resumen')->where(compact('entorno', 'fecha'))->lockForUpdate()->first();
            $max = (int) ResumenDiario::where('entorno', $entorno)->whereDate('fecha_generacion', $fecha)->max('correlativo');
            $numero = max($max, (int) ($fila->correlativo ?? 0)) + 1;
            ReglasFiscales::exigir($numero <= 99999, 'Se agotó la secuencia de resúmenes del día.');
            $servicio = app(XmlFiscal::class);
            $documento = $servicio->resumenAgrupado(array_values($boletas->all()), $numero, $hoy);
            $xml = $servicio->firmar($documento, app(CertificadoFiscal::class)->cargar($config), array_values($boletas->all()));
            $ruta = 'resumenes/'.$entorno.'/'.$clave.'/'.$documento->getName().'.xml';
            ReglasFiscales::exigir(Storage::disk('local')->put($ruta, $xml) === true, 'No se pudo guardar el resumen firmado.');
            $r = ResumenDiario::create(['empresa_id' => $empresa->id, 'usuario_id' => $actor->id, 'clave_idempotencia' => $clave, 'entorno' => $entorno, 'nombre' => $documento->getName(), 'correlativo' => $numero, 'fecha_emision' => $primera->fecha_emision, 'fecha_generacion' => $hoy, 'moneda' => $primera->moneda, 'total' => $total, 'emisor' => $primera->emisor, 'estado' => 'pendiente', 'xml_ruta' => $ruta, 'xml_sha256' => hash('sha256', $xml)]);
            foreach ($boletas as $i => $c) {
                $r->detalles()->create(['comprobante_id' => $c->id, 'comprobante_activo_id' => $c->id, 'linea' => $i + 1, 'numero_fiscal' => XmlFiscal::numeroFiscal($c)]);
            }
            DB::table('secuencias_resumen')->updateOrInsert(compact('entorno', 'fecha'), ['correlativo' => $numero]);
            $this->evento($actor, $r, 'generacion', 'Resumen firmado y reservado; todavía no enviado.');

            return $r;
        }, 3);
    }

    public function enviar(User $actor, int $id): ResumenDiario
    {
        $salida = DB::transaction(function () use ($actor, $id): ?array {
            $empresa = Empresa::where('singleton', 1)->lockForUpdate()->firstOrFail();
            $config = ConfiguracionFacturacion::where('empresa_id', $empresa->id)->lockForUpdate()->firstOrFail();
            $r = ResumenDiario::lockForUpdate()->findOrFail($id);
            AccesoResumenes::autorizar($actor, $r, 'comprobantes.emitir');
            if ($r->envio_historico_id !== null || $r->envio_iniciado_at !== null) {
                return null; // Never repeat any send, including an uncertain or rejected one.
            }
            $this->configuracion($empresa, $config, $r);
            $this->plazo($r->fecha_emision);
            ReglasFiscales::exigir($r->estado === 'pendiente', 'El resumen no está pendiente de envío.');
            $xml = $this->leerXml($r->xml_ruta, $r->xml_sha256 ?? '');
            $r->update(['estado' => 'en_proceso', 'envio_iniciado_at' => now()]);
            $this->evento($actor, $r, 'envio_iniciado', 'Solicitud de envío reservada; no se permite repetirla.');

            return [$r, $config, $xml];
        }, 3);
        if ($salida) {
            [$r, $config, $xml] = $salida;
            try {
                $respuesta = app(TransporteSunat::class)->enviar($config, (string) $r->emisor['ruc'], '03', $r->nombre, $xml);
                if ($respuesta->ticket === null || $respuesta->ticket === '') {
                    throw new FalloSunat('respuesta_invalida');
                }
                DB::transaction(function () use ($actor, $id, $respuesta): void {
                    $r = ResumenDiario::lockForUpdate()->findOrFail($id);
                    if ($r->ticket !== null || $this->terminal($r)) {
                        return;
                    }
                    $r->update(['estado' => 'enviado', 'ticket' => $respuesta->ticket, 'envio_finalizado_at' => now(), 'mensaje' => 'SUNAT devolvió un ticket; pendiente de CDR verificable.']);
                    $this->boletas($r)->each(fn ($c) => $c->update(['estado_sunat' => 'enviado']));
                    $this->evento($actor, $r, 'ticket_recibido', 'Ticket conservado en el resumen. No constituye aceptación.');
                }, 3);
            } catch (Throwable $error) {
                $this->fallo($actor, $id, $error, false, 'transporte');
            }
        }

        return ResumenDiario::findOrFail($id);
    }

    public function consultar(User $actor, int $id): ResumenDiario
    {
        $r = ResumenDiario::findOrFail($id);
        AccesoResumenes::autorizar($actor, $r);
        if ($r->envio_historico_id !== null) {
            app(EnviarComprobante::class)->consultar($actor, $r->detalles()->firstOrFail()->comprobante_id);

            $actualizado = app(ImportarResumenesHistoricos::class)->sincronizar($r->envio_historico_id) ?? $r;
            $this->evento($actor, $actualizado, 'consulta_historica', 'Consulta delegada al registro unitario original; se conserva su resultado y diagnóstico.', $actualizado->diagnostico, $actualizado->codigo_respuesta);

            return $actualizado;
        }
        $salida = DB::transaction(function () use ($actor, $id): ?array {
            $empresa = Empresa::where('singleton', 1)->lockForUpdate()->firstOrFail();
            $config = ConfiguracionFacturacion::where('empresa_id', $empresa->id)->lockForUpdate()->firstOrFail();
            $r = ResumenDiario::lockForUpdate()->findOrFail($id);
            AccesoResumenes::autorizar($actor, $r);
            if ($this->terminal($r) || ($r->consultando && $r->consulta_iniciada_at?->gt(now()->subMinutes(2)))) {
                return null;
            }
            $this->configuracion($empresa, $config, $r);
            ReglasFiscales::exigir($r->ticket !== null && $r->ticket !== '', 'No hay ticket verificable. Concilia el envío; no lo repitas.');
            $r->update(['consultando' => true, 'consulta_iniciada_at' => now(), 'consulta_finalizada_at' => null]);

            return [$r, $config];
        }, 3);
        if ($salida) {
            [$r, $config] = $salida;
            $etapa = 'transporte';
            try {
                $respuesta = app(TransporteSunat::class)->consultarTicket($config, (string) $r->emisor['ruc'], $r->ticket ?? '', $r->entorno);
                if ($respuesta->cdr === null) {
                    DB::transaction(function () use ($actor, $id, $respuesta): void {
                        $r = ResumenDiario::lockForUpdate()->findOrFail($id);
                        if ($this->terminal($r)) {
                            return;
                        }
                        $r->update(['consultando' => false, 'consulta_finalizada_at' => now()]);
                        $this->evento($actor, $r, 'consulta_pendiente', 'La consulta no devolvió CDR; se conserva el ticket y el estado pendiente.', null, $respuesta->codigo);
                    }, 3);
                } else {
                    $etapa = 'cdr_local';
                    $this->registrarCdr($actor, $r, $respuesta->cdr);
                }
            } catch (Throwable $error) {
                $this->fallo($actor, $id, $error, true, $etapa);
            }
        }

        return ResumenDiario::findOrFail($id);
    }

    public function recuperarInterrumpido(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $r = ResumenDiario::lockForUpdate()->findOrFail($id);
            AccesoResumenes::autorizar($actor, $r);
            if ($r->envio_historico_id !== null) {
                return;
            }
            if ($r->estado === 'en_proceso' && $r->ticket === null && $r->envio_iniciado_at?->lte(now()->subMinutes(2))) {
                $r->update(['estado' => 'resultado_incierto', 'envio_finalizado_at' => now()]);
                $this->evento($actor, $r, 'envio_interrumpido', 'Proceso interrumpido sin respuesta verificable. Concilia el resultado; no se permite reenviar.');
            } elseif ($r->consultando && $r->consulta_iniciada_at?->lte(now()->subMinutes(2))) {
                $r->update(['consultando' => false, 'consulta_finalizada_at' => now()]);
                $this->evento($actor, $r, 'consulta_interrumpida', 'Se conserva el ticket y el estado anterior; puede solicitarse otra consulta.');
            }
        }, 3);
    }

    private function registrarCdr(User $actor, ResumenDiario $r, #[\SensitiveParameter] string $contenido): void
    {
        if (strlen($contenido) > 2 * 1024 * 1024) {
            throw new FalloSunat('cdr_tamano');
        }
        $ruta = 'resumenes/'.$r->entorno.'/'.$r->clave_idempotencia.'/recibidos/'.Str::uuid().'.zip';
        if (! Storage::disk('local')->put($ruta, $contenido)) {
            throw new FalloSunat('cdr_guardado');
        }
        ResumenDiario::whereKey($r->id)->whereNull('cdr_recibido_ruta')->update(['cdr_recibido_ruta' => $ruta, 'cdr_recibido_sha256' => hash('sha256', $contenido)]);
        $cdr = app(LeerCdr::class)->leer($contenido, $r->nombre, (string) $r->emisor['ruc']);
        $estado = $cdr['codigo'] === '0' ? ($cdr['observaciones'] ? 'observado' : 'aceptado') : ((int) $cdr['codigo'] >= 2000 ? 'rechazado' : null);
        if ($estado === null) {
            throw new FalloSunat('cdr_no_definitivo');
        }
        DB::transaction(function () use ($actor, $r, $cdr, $estado, $ruta): void {
            $r = ResumenDiario::lockForUpdate()->findOrFail($r->id);
            if ($this->terminal($r)) {
                return;
            }
            $r->update(['estado' => $estado, 'codigo_respuesta' => $cdr['codigo'], 'mensaje' => $cdr['mensaje'].($cdr['observaciones'] ? "\n".implode("\n", $cdr['observaciones']) : ''), 'cdr_ruta' => $ruta, 'consultando' => false, 'consulta_finalizada_at' => now()]);
            foreach ($this->boletas($r) as $c) {
                // SUNAT rejects the whole RC, not the individual boleta numbers (manual 4.1).
                $c->update(['estado_sunat' => $estado === 'rechazado' ? 'pendiente' : $estado]);
            }
            if ($estado === 'rechazado') {
                $r->detalles()->update(['comprobante_activo_id' => null]);
            }
            $this->evento($actor, $r, 'cdr_verificado', $r->mensaje ?? '', null, $cdr['codigo']);
        }, 3);
    }

    /** @return Collection<int,Comprobante> */
    private function boletas(ResumenDiario $r): Collection
    {
        return Comprobante::whereIn('id', $r->detalles()->select('comprobante_id'))->orderBy('id')->lockForUpdate()->get();
    }

    private function configuracion(Empresa $e, ConfiguracionFacturacion $config, ResumenDiario $r): void
    {
        ReglasFiscales::exigir($config->habilitada && $config->entorno->value === $r->entorno && $config->empresa_id === $r->empresa_id && $e->ruc === ($r->emisor['ruc'] ?? null) && $config->usuario_sol !== null && $config->clave_sol !== null, 'El emisor, entorno o credenciales actuales no corresponden al resumen.');
    }

    private function plazo(CarbonImmutable $fecha): void
    {
        $hoy = CarbonImmutable::now('America/Lima')->startOfDay();
        ReglasFiscales::exigir($fecha->startOfDay()->lte($hoy) && $fecha->addDays(7)->startOfDay()->gte($hoy), 'La fecha de emisión está fuera del plazo de siete días para informar el resumen.');
    }

    private function leerXml(?string $ruta, string $hash): string
    {
        ReglasFiscales::exigir($ruta !== null && Storage::disk('local')->exists($ruta), 'No se encontró el XML firmado.');
        $xml = Storage::disk('local')->get($ruta);
        ReglasFiscales::exigir(hash_equals($hash, hash('sha256', $xml)), 'El XML firmado cambió. No se enviará.');

        return $xml;
    }

    private function terminal(ResumenDiario $r): bool
    {
        return in_array($r->estado, ['aceptado', 'observado', 'rechazado'], true);
    }

    /** @param array<string,mixed>|null $diagnostico */
    private function evento(User $actor, ResumenDiario $r, string $operacion, string $mensaje, ?array $diagnostico = null, ?string $codigo = null): void
    {
        $r->eventos()->create(['usuario_id' => $actor->id, 'operacion' => $operacion, 'estado' => $r->estado, 'codigo' => $codigo, 'mensaje' => $mensaje, 'diagnostico' => $diagnostico, 'created_at' => now()]);
        activity()->causedBy($actor)->performedOn($r)->withProperties(['operacion' => $operacion, 'estado' => $r->estado, 'codigo' => $codigo])->log('Operación de resumen diario');
    }

    private function fallo(User $actor, int $id, #[\SensitiveParameter] Throwable $error, bool $consulta, string $etapa): void
    {
        $d = ($error instanceof FalloSunat || $etapa === 'transporte' ? FalloSunat::desde($error) : new FalloSunat('error_no_clasificado'))->seguro() + ['referencia' => (string) Str::uuid(), 'etapa' => $etapa, 'metodo_soap' => $consulta ? 'getStatus' : 'sendSummary'];
        DB::transaction(function () use ($actor, $id, $consulta, $d): void {
            $r = ResumenDiario::lockForUpdate()->findOrFail($id);
            if ($this->terminal($r) || (! $consulta && $r->ticket !== null)) {
                return;
            }
            $r->update(['diagnostico' => $d, 'consultando' => false, ($consulta ? 'consulta_finalizada_at' : 'envio_finalizado_at') => now(), 'estado' => $consulta ? $r->estado : 'resultado_incierto']);
            $this->evento($actor, $r, $consulta ? 'consulta_sin_respuesta' : 'envio_incierto', $d['mensaje'], $d);
            Log::warning('Diagnóstico seguro de resumen SUNAT', $d + ['resumen_id' => $r->id, 'entorno' => $r->entorno]);
        }, 3);
    }
}
