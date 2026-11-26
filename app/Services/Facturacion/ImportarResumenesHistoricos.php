<?php

namespace App\Services\Facturacion;

use App\Models\ComprobanteEnvio;
use App\Models\Empresa;
use App\Models\ResumenDiario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Metadata-only bridge: never signs, sends, reads secrets or updates original records. */
class ImportarResumenesHistoricos
{
    public function importar(): int
    {
        $total = 0;
        ComprobanteEnvio::whereNotNull('xml_nombre')->whereHas('comprobante', fn ($q) => $q->where('configuracion_tributaria->tipo', '03'))->orderBy('id')->chunkById(100, function ($envios) use (&$total): void {
            foreach ($envios as $envio) {
                $this->sincronizar($envio->id);
                $total++;
            }
        });

        return $total;
    }

    public function sincronizar(int $envioId): ?ResumenDiario
    {
        return DB::transaction(function () use ($envioId): ?ResumenDiario {
            $empresa = Empresa::where('singleton', 1)->lockForUpdate()->first();
            if (! $empresa) {
                return null;
            }
            $e = ComprobanteEnvio::with('comprobante')->findOrFail($envioId);
            $c = $e->comprobante;
            if (! preg_match('/^(\d{11})-RC-(\d{8})-([1-9]\d{0,4})$/D', $e->xml_nombre ?? '', $m) || XmlFiscal::dato($c, 'tipo') !== '03') {
                return null;
            }
            $fecha = CarbonImmutable::createFromFormat('!Ymd', $m[2]);
            ReglasFiscales::exigir($fecha !== null && $fecha->format('Ymd') === $m[2] && $m[1] === ($c->emisor['ruc'] ?? null), 'El resumen unitario histórico requiere revisión de su identidad. No se modificó el original.');
            $entorno = XmlFiscal::dato($c, 'entorno');
            ReglasFiscales::exigir(in_array($entorno, ['beta', 'produccion'], true), 'Entorno histórico inválido.');
            $r = ResumenDiario::where('envio_historico_id', $e->id)->lockForUpdate()->first();
            $datos = [
                'estado' => $e->estado->value, 'ticket' => $e->ticket, 'xml_ruta' => $e->xml_ruta, 'xml_sha256' => $e->xml_sha256,
                'cdr_ruta' => $e->cdr_ruta, 'cdr_recibido_ruta' => $e->cdr_recibido_ruta, 'cdr_recibido_sha256' => $e->cdr_recibido_sha256,
                'codigo_respuesta' => $e->codigo_respuesta, 'mensaje' => $e->mensaje, 'diagnostico' => $e->diagnostico_consulta ?? $e->diagnostico_envio,
                'envio_iniciado_at' => $e->iniciado_at, 'envio_finalizado_at' => $e->finalizado_at, 'consultando' => $e->consultando,
                'consulta_iniciada_at' => $e->consulta_iniciada_at, 'consulta_finalizada_at' => $e->consulta_finalizada_at,
            ];
            if ($r) {
                $r->fill($datos);
                if ($r->isDirty()) {
                    $r->save();
                }

                return $r;
            }
            $r = ResumenDiario::create($datos + [
                'empresa_id' => $empresa->id, 'usuario_id' => $e->usuario_id, 'envio_historico_id' => $e->id, 'clave_idempotencia' => (string) Str::uuid(),
                'entorno' => $entorno, 'nombre' => $e->xml_nombre, 'correlativo' => (int) $m[3], 'fecha_emision' => $c->fecha_emision,
                'fecha_generacion' => $fecha, 'moneda' => $c->moneda, 'total' => $c->total, 'emisor' => $c->emisor,
            ]);
            $r->detalles()->create(['comprobante_id' => $c->id, 'comprobante_activo_id' => $c->id, 'linea' => 1, 'numero_fiscal' => XmlFiscal::numeroFiscal($c)]);
            $r->eventos()->create(['usuario_id' => $e->usuario_id, 'operacion' => 'vinculacion_historica', 'estado' => $e->estado->value, 'mensaje' => 'Registro unitario vinculado sin modificar documentos, ticket ni estado de origen.', 'created_at' => now()]);
            // Never lower or restart a sequence already used by the compatible sender.
            $fila = DB::table('secuencias_resumen')->where('entorno', $entorno)->where('fecha', $fecha->toDateString())->lockForUpdate()->first();
            if (! $fila || (int) $fila->correlativo < (int) $m[3]) {
                DB::table('secuencias_resumen')->updateOrInsert(['entorno' => $entorno, 'fecha' => $fecha->toDateString()], ['correlativo' => (int) $m[3]]);
            }

            return $r;
        }, 3);
    }
}
