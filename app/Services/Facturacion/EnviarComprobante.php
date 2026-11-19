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
use Illuminate\Support\Facades\Storage;
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
            if ($tipo === '03') {
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
            try {
                $respuesta = app(TransporteSunat::class)->enviar(ConfiguracionFacturacion::firstOrFail(), (string) $c->emisor['ruc'], XmlFiscal::dato($c, 'tipo'), $salida->xml_nombre ?? '', $this->xml($salida->xml_ruta, $salida->xml_sha256 ?? ''));
                $this->registrar($actor, $salida->id, $respuesta);
            } catch (Throwable) {
                $this->incierto($salida->id, false);
            }
        }

        return Comprobante::findOrFail($id);
    }

    public function consultar(User $actor, int $id): Comprobante
    {
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
            if ($ocupado && $envio->iniciado_at?->gt(now()->subMinutes(2))) {
                return null;
            }
            ReglasFiscales::exigir(XmlFiscal::dato($c, 'tipo') === '01' ? $config->entorno->value === 'produccion' : $envio->ticket !== null, 'Este resultado requiere conciliación manual: la consulta necesita un ticket de resumen o una factura de producción.');
            $envio->update(['consultando' => true, 'iniciado_at' => now()]);

            return $envio;
        }, 3);
        if ($salida !== null) {
            try {
                $respuesta = app(TransporteSunat::class)->consultar(ConfiguracionFacturacion::firstOrFail(), Comprobante::findOrFail($id), $salida->ticket);
                $this->registrar($actor, $salida->id, $respuesta);
            } catch (Throwable) {
                $this->incierto($salida->id, true);
            }
        }

        return Comprobante::findOrFail($id);
    }

    public function recuperarInterrumpido(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $c = Comprobante::lockForUpdate()->findOrFail($id);
            AccesoFacturacion::cobro($actor, $c->cobro()->firstOrFail());
            $envio = $c->envios()->lockForUpdate()->orderByDesc('id')->first();
            if (! $envio || ! $envio->iniciado_at?->lte(now()->subMinutes(2))) {
                return;
            }
            if ($envio->estado === EnvioEstado::EN_PROCESO) {
                $envio->update(['estado' => 'resultado_incierto', 'consultando' => false, 'finalizado_at' => now(), 'mensaje' => 'El envío se interrumpió sin respuesta verificable. Consulta o concilia el resultado antes de repetirlo.']);
                $c->update(['estado_sunat' => 'desconocido']);
            } elseif ($envio->consultando) {
                $envio->update(['consultando' => false, 'finalizado_at' => now(), 'mensaje' => 'La consulta anterior se interrumpió. Puedes volver a consultar el resultado.']);
            }
        }, 3);
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

    private function registrar(User $actor, int $envioId, RespuestaSunat $r): void
    {
        $envioLeido = ComprobanteEnvio::findOrFail($envioId);
        $c = Comprobante::findOrFail($envioLeido->comprobante_id);
        $cdr = $r->cdr !== null ? app(LeerCdr::class)->leer($r->cdr, $envioLeido->xml_nombre ?? '', (string) $c->emisor['ruc']) : null;
        $ruta = null;
        if ($cdr !== null) {
            $ruta = 'comprobantes/'.XmlFiscal::dato($c, 'entorno').'/'.$c->clave_idempotencia.'/R-'.$envioLeido->xml_nombre.'.zip';
            ReglasFiscales::exigir(Storage::disk('local')->put($ruta, $r->cdr) === true, 'No se pudo guardar el CDR.');
        }
        DB::transaction(function () use ($actor, $envioId, $r, $cdr, $ruta): void {
            $leido = ComprobanteEnvio::findOrFail($envioId);
            $c = Comprobante::lockForUpdate()->findOrFail($leido->comprobante_id);
            $envio = ComprobanteEnvio::lockForUpdate()->findOrFail($envioId);
            if (in_array($c->estado_sunat, [SunatEstado::ACEPTADO, SunatEstado::OBSERVADO, SunatEstado::RECHAZADO], true)) {
                return;
            }
            if ($cdr !== null) {
                $estado = $cdr['codigo'] === '0' ? ($cdr['observaciones'] ? 'observado' : 'aceptado') : ((int) $cdr['codigo'] >= 2000 ? 'rechazado' : 'desconocido');
                $c->update(['estado_sunat' => $estado, 'cdr_ruta' => $ruta]);
                $envio->update(['estado' => $estado === 'desconocido' ? 'resultado_incierto' : $estado, 'codigo_respuesta' => $cdr['codigo'], 'mensaje' => $cdr['mensaje'].($cdr['observaciones'] ? "\n".implode("\n", $cdr['observaciones']) : ''), 'cdr_ruta' => $ruta, 'consultando' => false, 'finalizado_at' => now()]);
            } elseif ($r->ticket !== null) {
                $envio->update(['estado' => 'enviado', 'ticket' => $r->ticket, 'mensaje' => 'Resumen recibido. Consulta el ticket para obtener el CDR.', 'finalizado_at' => now()]);
                $c->update(['estado_sunat' => 'enviado']);
            } elseif ($r->pendiente) {
                $envio->update(['consultando' => false, 'codigo_respuesta' => $r->codigo, 'mensaje' => $r->codigo === '98' ? 'SUNAT continúa procesando el resumen.' : 'La consulta aún no devolvió un CDR. Conserva el documento y concilia el resultado.', 'finalizado_at' => now()]);
            }
            activity()->causedBy($actor)->performedOn($c)->withProperties(['estado_sunat' => $c->estado_sunat->value, 'envio' => $envioId])->log('Respuesta de facturación registrada');
        }, 3);
    }

    private function incierto(int $id, bool $consulta): void
    {
        DB::transaction(function () use ($id, $consulta): void {
            $leido = ComprobanteEnvio::findOrFail($id);
            $c = Comprobante::lockForUpdate()->findOrFail($leido->comprobante_id);
            $envio = ComprobanteEnvio::lockForUpdate()->findOrFail($id);
            if (in_array($c->estado_sunat, [SunatEstado::ACEPTADO, SunatEstado::OBSERVADO, SunatEstado::RECHAZADO], true)) {
                return;
            }
            $envio->update(['consultando' => false, 'estado' => $consulta ? $envio->estado->value : 'resultado_incierto', 'mensaje' => $consulta ? 'No se pudo completar la consulta. Puedes consultar nuevamente.' : 'No se obtuvo una respuesta verificable. No repitas el envío; consulta o concilia el resultado.', 'finalizado_at' => now()]);
            if (! $consulta) {
                $c->update(['estado_sunat' => 'desconocido']);
            }
        }, 3);
    }
}
