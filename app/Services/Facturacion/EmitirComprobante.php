<?php

namespace App\Services\Facturacion;

use App\Enums\EstadoCobro;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\Pedido;
use App\Models\SerieComprobante;
use App\Models\User;
use App\Services\Configuracion\IdentidadVisual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use NumberFormatter;

class EmitirComprobante
{
    /**
     * @param  array{serieId:int,tipo:string,numero:string,nombre:string,direccion:string}  $receptor
     * @param  array<int|string,string>  $afectaciones
     */
    public function emitir(User $actor, int $cobroId, string $clave, array $receptor, array $afectaciones, string $version): Comprobante
    {
        Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $cobroId, $clave, $receptor, $afectaciones, $version): Comprobante {
            $empresa = Empresa::where('singleton', 1)->lockForUpdate()->firstOrFail();
            $config = ConfiguracionFacturacion::where('empresa_id', $empresa->id)->lockForUpdate()->firstOrFail();
            $serie = SerieComprobante::with('tipoComprobante')->lockForUpdate()->findOrFail($receptor['serieId']);
            $cobroLeido = Cobro::findOrFail($cobroId);
            Pedido::whereKey($cobroLeido->pedido_id)->lockForUpdate()->firstOrFail();
            $cobro = Cobro::lockForUpdate()->findOrFail($cobroId);
            AccesoFacturacion::cobro($actor, $cobro, 'comprobantes.emitir');
            if ($existente = Comprobante::where('cobro_id', $cobroId)->where('entorno', $config->entorno->value)->first()) {
                return $existente;
            }
            if ($existente = Comprobante::where('clave_idempotencia', $clave)->first()) {
                ReglasFiscales::exigir($existente->cobro_id === $cobroId && $existente->usuario_id === $actor->id && $existente->entorno === $config->entorno->value, 'Esta solicitud ya corresponde a otro comprobante.');

                return $existente;
            }
            ReglasFiscales::exigir(hash_equals(ConfigurarFacturacion::version($empresa, $config), $version), 'La configuración fiscal cambió. Revisa nuevamente la emisión.');
            $fecha = CarbonImmutable::now('America/Lima');
            self::configurada($empresa, $config, $fecha);
            ReglasFiscales::exigir($serie->activo && $serie->tipoComprobante->activo && in_array($serie->tipoComprobante->codigo, ['01', '03'], true) && $serie->entorno === $config->entorno->value, 'Selecciona una serie activa del entorno configurado.');
            ReglasFiscales::exigir((bool) preg_match($serie->tipoComprobante->codigo === '01' ? '/^F[A-Z0-9]{3}$/' : '/^B[A-Z0-9]{3}$/', $serie->serie), 'La serie no corresponde al tipo de comprobante.');
            ReglasFiscales::exigir($serie->ultimo_correlativo < 99999999, 'Se agotó la numeración de esta serie.');
            ReglasFiscales::exigir($cobro->estado === EstadoCobro::CONFIRMADO && $cobro->moneda === 'PEN', 'Solo se pueden emitir cobros confirmados en soles.');
            ReglasFiscales::exigir(! $cobro->devoluciones()->where('estado', 'confirmada')->exists(), 'Este cobro tiene devoluciones. Revisa su regularización tributaria antes de emitir.');
            $pagado = '0.00';
            foreach ($cobro->pagos()->get() as $pago) {
                $pagado = bcadd($pagado, $pago->monto, 2);
            }
            ReglasFiscales::exigir(bccomp($pagado, $cobro->total, 2) === 0, 'Los pagos no cuadran con el cobro confirmado.');
            Validator::make($receptor, ['tipo' => ['required', 'in:0,1,6'], 'numero' => ['nullable', 'string', 'max:11'], 'nombre' => ['required', 'string', 'max:200'], 'direccion' => ['nullable', 'string', 'max:250']])->validate();
            $tipo = $serie->tipoComprobante->codigo;
            ReglasFiscales::exigir($tipo !== '01' || ($receptor['tipo'] === '6' && trim($receptor['direccion']) !== ''), 'La factura requiere RUC, razón social y dirección.');
            ReglasFiscales::exigir($receptor['tipo'] !== '6' || ReglasFiscales::ruc($receptor['numero']), 'El RUC del cliente no es válido.', 'numero');
            ReglasFiscales::exigir($receptor['tipo'] !== '1' || (bool) preg_match('/^\d{8}$/', $receptor['numero']), 'El DNI debe tener 8 dígitos.', 'numero');
            ReglasFiscales::exigir($receptor['tipo'] !== '0' || ($tipo === '03' && bccomp($cobro->total, '700', 2) <= 0), 'Para importes superiores a S/ 700 identifica al cliente con DNI o RUC.');
            $pem = app(CertificadoFiscal::class)->cargar($config);
            $tasa = $config->tasa_impuesto;
            ReglasFiscales::exigir($tasa !== null, 'Configura la tasa tributaria.');
            $calculo = app(CalculoTributario::class)->calcular($cobro, $tasa, $afectaciones);
            $letras = (new NumberFormatter('es_PE', NumberFormatter::SPELLOUT))->format((int) explode('.', $cobro->total)[0]);
            ReglasFiscales::exigir(is_string($letras), 'No se pudo expresar el importe en letras.');
            $snapshot = ['tipo' => $tipo, 'serie' => $serie->serie, 'entorno' => $config->entorno->value, 'tasa' => $config->tasa_impuesto,
                'tipografia' => app(IdentidadVisual::class)->tipografia(), 'fecha_hora' => $fecha->toIso8601String(), 'leyenda' => 'SON '.mb_strtoupper($letras).' CON '.explode('.', $cobro->total)[1].'/100 SOLES'];
            foreach (['gravadas', 'exoneradas', 'inafectas', 'igv_gratuitas'] as $campo) {
                $snapshot[$campo] = $calculo[$campo];
            }
            $c = Comprobante::create([
                'entorno' => $config->entorno->value, 'serie_comprobante_id' => $serie->id, 'correlativo' => $serie->ultimo_correlativo + 1, 'cobro_id' => $cobroId,
                'usuario_id' => $actor->id, 'fecha_emision' => $fecha->toDateString(), 'moneda' => 'PEN', 'estado' => 'emitido', 'estado_sunat' => 'pendiente',
                'emisor' => $empresa->only(['ruc', 'razon_social', 'nombre_comercial', 'direccion', 'ubigeo', 'departamento', 'provincia', 'distrito', 'logo_ruta']),
                'receptor' => ['tipo' => $receptor['tipo'], 'numero' => $receptor['tipo'] === '0' ? '-' : $receptor['numero'], 'nombre' => trim($receptor['nombre']), 'direccion' => trim($receptor['direccion'])],
                'configuracion_tributaria' => $snapshot, 'valor_venta' => $calculo['valor_venta'], 'impuestos' => $calculo['impuestos'],
                'operaciones_gratuitas' => $calculo['gratuitas'], 'descuento' => $calculo['descuento'], 'total' => $cobro->total, 'clave_idempotencia' => $clave,
            ]);
            foreach ($calculo['lineas'] as $linea) {
                $c->detalles()->create($linea);
            }
            $xmlService = app(XmlFiscal::class);
            $xml = $xmlService->firmar($xmlService->factura($c), $pem);
            // UUID path: retries never overwrite an already committed document.
            $ruta = 'comprobantes/'.$config->entorno->value.'/'.$clave.'/'.XmlFiscal::nombre($c).'.xml';
            ReglasFiscales::exigir(Storage::disk('local')->put($ruta, $xml) === true, 'No se pudo guardar el XML firmado.');
            $c->update(['xml_ruta' => $ruta, 'hash_documento' => $xmlService->hash($xml), 'configuracion_tributaria' => [...$snapshot, 'xml_sha256' => hash('sha256', $xml)]]);
            $serie->update(['ultimo_correlativo' => $c->correlativo]);
            activity()->causedBy($actor)->performedOn($c)->withProperties(['numero' => XmlFiscal::numero($c), 'entorno' => $config->entorno->value, 'cobro' => $cobroId])->log('Comprobante emitido y firmado');

            return $c;
        }, 3);
    }

    public static function configurada(Empresa $empresa, ConfiguracionFacturacion $config, CarbonImmutable $fecha): void
    {
        ReglasFiscales::exigir($config->habilitada, 'Habilita la facturación en Configuración del sistema.');
        foreach (['ruc', 'razon_social', 'direccion', 'ubigeo', 'departamento', 'provincia', 'distrito'] as $campo) {
            ReglasFiscales::exigir(is_string($empresa->$campo) && trim($empresa->$campo) !== '', 'Completa los datos fiscales y la dirección de la empresa.');
        }
        ReglasFiscales::exigir(ReglasFiscales::ruc($empresa->ruc ?? '') && $empresa->moneda === 'PEN' && $empresa->precios_incluyen_impuestos, 'Revisa el RUC y los precios finales en soles de la empresa.');
        ReglasFiscales::exigir($config->tasa_impuesto !== null && bccomp($config->tasa_impuesto, '0', 4) > 0 && bccomp($config->tasa_impuesto, '100', 4) <= 0 && $config->vigente_desde !== null && $config->vigente_desde->toDateString() <= $fecha->toDateString() && ($config->vigente_hasta === null || $config->vigente_hasta->toDateString() >= $fecha->toDateString()), 'Define una tasa tributaria y una vigencia válidas antes de emitir.');
        ReglasFiscales::exigir($config->usuario_sol !== null && $config->clave_sol !== null, 'Configura el usuario y la clave SOL.');
    }
}
