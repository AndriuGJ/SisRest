<?php

namespace App\Services\Cobros;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ConfiguracionFacturacion;
use App\Models\Empresa;
use App\Models\Pedido;
use App\Models\ProcesoCobro;
use App\Models\SerieComprobante;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Configuracion\GestionImpresoras;
use App\Services\Facturacion\AccesoFacturacion;
use App\Services\Facturacion\CertificadoFiscal;
use App\Services\Facturacion\ConfigurarFacturacion;
use App\Services\Facturacion\EmitirComprobante;
use App\Services\Facturacion\ReglasFiscales;
use App\Services\Impresion\AccesoImpresion;
use App\Services\Impresion\ImpresionOperativa;
use App\Services\Pos\AccesoPos;
use App\Services\Pos\TransaccionPos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @phpstan-type Datos array{clienteId:string,grupoPagador:string,incluirEnvio:bool,descuentoTipo:string,descuentoValor:string,motivoId:string,motivoDetalle:string}
 * @phpstan-type Seleccion array<string,array{detalleId:string,cantidad:string}>
 * @phpstan-type Pagos array<string,array{medioId:string,monto:string,entregado:string,referencia:string}>
 * @phpstan-type Fiscal array{documento:string,serieId:int,receptor:array{tipo:string,numero:string,nombre:string,direccion:string},afectaciones:array<int|string,string>,version:string}
 */
class ProcesarVenta
{
    use TransaccionPos;

    /** @param array{cliente:array{id:int|null},total:numeric-string,envio:numeric-string,lineas:array<int,array{detalleId:int,subtotal:numeric-string,total:numeric-string}>} $plan
     * @param  array<int|string,string>  $afectaciones
     * @return Fiscal
     */
    public function fiscal(User $actor, array $plan, string $documento, string $serieId, array $afectaciones): array
    {
        Validator::make(['documento' => $documento], ['documento' => ['required', 'in:interno,01,03']])->validate();
        $resultado = ['documento' => $documento, 'serieId' => 0, 'receptor' => ['tipo' => '0', 'numero' => '', 'nombre' => 'CLIENTE VARIOS', 'direccion' => ''], 'afectaciones' => [], 'version' => ''];
        if ($documento === 'interno') {
            return $resultado;
        }
        AccesoFacturacion::usuario($actor, 'comprobantes.emitir');
        $empresa = Empresa::firstOrFail();
        $config = ConfiguracionFacturacion::firstOrFail();
        EmitirComprobante::configurada($empresa, $config, CarbonImmutable::now('America/Lima'));
        app(CertificadoFiscal::class)->cargar($config);
        Validator::make(['serieId' => $serieId], ['serieId' => ['required', 'integer', 'exists:series_comprobante,id']])->validate();
        $serie = SerieComprobante::with('tipoComprobante')->findOrFail($serieId);
        ReglasFiscales::exigir($serie->activo && $serie->tipoComprobante->activo && $serie->tipoComprobante->codigo === $documento && $serie->entorno === $config->entorno->value, 'Selecciona una serie activa del documento y entorno actuales.');
        ReglasFiscales::exigir($serie->ultimo_correlativo < 99999999 && (bool) preg_match($documento === '01' ? '/^F[A-Z0-9]{3}$/' : '/^B[A-Z0-9]{3}$/', $serie->serie), 'Revisa la serie y su numeración disponible.');
        $cliente = isset($plan['cliente']['id']) ? Cliente::with('tipoDocumento')->findOrFail($plan['cliente']['id']) : null;
        $tipo = $cliente->tipoDocumento->codigo ?? '0';
        $numero = $cliente->numero_documento ?? '';
        $nombre = $cliente->nombre ?? 'CLIENTE VARIOS';
        $direccion = $cliente->direccion ?? '';
        ReglasFiscales::exigir(trim($nombre) !== '', 'Completa los nombres o razón social del cliente.', 'clienteId');
        ReglasFiscales::exigir(in_array($tipo, ['0', '1', '6'], true), 'Selecciona un cliente con DNI o RUC para el documento electrónico.', 'clienteId');
        ReglasFiscales::exigir($documento !== '01' || ($tipo === '6' && trim($direccion) !== ''), 'La factura requiere cliente con RUC, razón social y dirección.', 'clienteId');
        ReglasFiscales::exigir($tipo !== '6' || ReglasFiscales::ruc($numero), 'El RUC del cliente no es válido.', 'clienteId');
        ReglasFiscales::exigir($tipo !== '1' || (bool) preg_match('/^[0-9]{8}$/', $numero), 'El DNI debe contener ocho dígitos.', 'clienteId');
        ReglasFiscales::exigir($tipo !== '0' || bccomp($plan['total'], '700', 2) <= 0, 'Para una boleta superior a S/ 700 selecciona un cliente identificado con DNI o RUC.', 'clienteId');
        $codigos = [];
        foreach ($plan['lineas'] as $linea) {
            if (bccomp($linea['subtotal'], '0', 2) === 0) {
                continue;
            }
            $codigo = $afectaciones[$linea['detalleId']] ?? '';
            if ($codigo === '' && bccomp($linea['total'], '0', 2) > 0) {
                $codigo = '10';
            }
            ReglasFiscales::exigir(isset(ReglasFiscales::AFECTACIONES[$codigo]) && ((bccomp($linea['total'], '0', 2) > 0) === in_array($codigo, ['10', '20', '30'], true)), 'Elige la afectación de cada consumo, incluidas las cortesías.', 'afectaciones');
            $codigos[$linea['detalleId']] = $codigo;
        }
        if (bccomp($plan['envio'], '0', 2) > 0) {
            $codigo = $afectaciones['envio'] ?? '10';
            ReglasFiscales::exigir(in_array($codigo, ['10', '20', '30'], true), 'Revisa la afectación del delivery.', 'afectaciones');
            $codigos['envio'] = $codigo;
        }

        return ['documento' => $documento, 'serieId' => $serie->id, 'receptor' => ['tipo' => $tipo, 'numero' => $numero, 'nombre' => $nombre, 'direccion' => $direccion], 'afectaciones' => $codigos, 'version' => ConfigurarFacturacion::version($empresa, $config)];
    }

    public function impresora(User $actor, int $cajaId): Caja
    {
        Gate::forUser(AccesoPos::usuario($actor))->authorize('impresion.tickets');
        $caja = AccesoImpresion::cajasTicket($actor)->with('impresora')->findOrFail($cajaId);
        $impresora = $caja->impresora;
        ReglasFiscales::exigir($impresora !== null && $impresora->activo && ! $impresora->area_preparacion_id && in_array($impresora->ancho_mm, [58, 80], true), 'Configura una impresora de caja activa de 58 u 80 mm para '.$caja->nombre.'.', 'turnoId');
        Validator::make(['host' => $impresora->host, 'puerto' => $impresora->puerto], ['host' => ['required', 'ipv4', 'not_in:0.0.0.0,255.255.255.255'], 'puerto' => ['required', 'integer', 'min:1', 'max:65535']])->validate();
        app(GestionImpresoras::class)->comprobarDisponible($impresora);

        return $caja;
    }

    /** @param Seleccion $seleccion */
    public function cubreCuenta(Pedido $pedido, array $seleccion, bool $envio): bool
    {
        if ($pedido->detalles()->where('estado', 'borrador')->exists() || (! $envio && bccomp(EstadoCuenta::envioPendiente($pedido), '0', 2) > 0)) {
            return false;
        }
        $cantidades = collect($seleccion)->keyBy('detalleId');
        foreach ($pedido->detalles()->whereNull('detalle_padre_id')->where('estado', 'confirmado')->get() as $d) {
            $cantidad = $cantidades->get((string) $d->id)['cantidad'] ?? '0';
            if (! is_numeric($cantidad) || ! preg_match('/^[0-9]{1,4}$/', $cantidad) || bccomp(bcadd($d->cantidad_cobrada, $cantidad, 4), bcsub($d->cantidad, $d->cantidad_anulada, 4), 4) < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Datos  $datos
     * @param  Seleccion  $seleccion
     * @param  Pagos  $pagos
     * @param  Fiscal  $fiscal
     */
    public function registrar(User $actor, int $pedidoId, int $turnoId, string $clave, string $huella, array $datos, array $seleccion, array $pagos, array $fiscal, bool $liberar): ProcesoCobro
    {
        return $this->ejecutar($actor, 'cobros.crear', function () use ($actor, $pedidoId, $turnoId, $clave, $huella, $datos, $seleccion, $pagos, $fiscal, $liberar): ProcesoCobro {
            $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);
            AccesoPos::pedido($actor, $pedido);
            if ($cobro = Cobro::where('clave_idempotencia', $clave)->lockForUpdate()->first()) {
                abort_unless($cobro->usuario_id === $actor->id && $cobro->pedido_id === $pedidoId && $cobro->turno_caja_id === $turnoId, 403);

                AccesoCajas::turno($actor, $cobro->turnoCaja);

                return ProcesoCobro::where('cobro_id', $cobro->id)->firstOrFail();
            }
            $turno = $this->turno($turnoId);
            AccesoCajas::turno($actor, $turno);
            $gestion = app(GestionCobros::class);
            $plan = $gestion->previsualizar($actor, $pedidoId, $turnoId, $datos, $seleccion, $pagos);
            ReglasFiscales::exigir(hash_equals($huella, $plan['huella']), 'Cambió el pedido, cliente o caja. Revisa de nuevo antes de cobrar.');
            $actual = $this->fiscal($actor, $plan, $fiscal['documento'], (string) $fiscal['serieId'], $fiscal['afectaciones']);
            ReglasFiscales::exigir(Arr::sortRecursive($actual) === Arr::sortRecursive($fiscal), 'Cambió la configuración o los datos fiscales. Revisa otra vez.');
            $this->impresora($actor, $turno->caja_id);
            if ($liberar) {
                Gate::forUser($actor)->authorize('pedidos.editar');
                Gate::forUser($actor)->authorize('entregas.confirmar');
                if ($pedido->mesa_id) {
                    Gate::forUser($actor)->authorize('mesas.liberar');
                }
                ReglasFiscales::exigir($this->cubreCuenta($pedido, $seleccion, $datos['incluirEnvio']), 'Quedan consumos o delivery por cobrar. Usa Cobrar y liberar después.');
            }
            $cobro = $gestion->cobrar($actor, $pedidoId, $turnoId, $clave, $huella, $datos, $seleccion, $pagos);

            return ProcesoCobro::create(['cobro_id' => $cobro->id, 'usuario_id' => $actor->id, 'caja_id' => $turno->caja_id, 'documento' => $fiscal['documento'], 'fiscal' => $fiscal, 'liberar' => $liberar, 'clave_emision' => (string) Str::uuid(), 'clave_impresion' => (string) Str::uuid()]);
        });
    }

    public function continuar(User $actor, int $procesoId): ProcesoCobro
    {
        $proceso = ProcesoCobro::findOrFail($procesoId);
        $cobro = Cobro::findOrFail($proceso->cobro_id);
        AccesoImpresion::cobro($actor, $cobro);
        abort_unless($proceso->usuario_id === $actor->id, 403);
        if ($proceso->finalizado_at) {
            return $proceso;
        }
        try {
            if ($proceso->documento !== 'interno') {
                $fiscal = $proceso->fiscal;
                $afectaciones = [];
                foreach ($cobro->detalles as $detalle) {
                    if (isset($fiscal['afectaciones'][$detalle->pedido_detalle_id])) {
                        $afectaciones[$detalle->id] = $fiscal['afectaciones'][$detalle->pedido_detalle_id];
                    }
                }
                if (isset($fiscal['afectaciones']['envio'])) {
                    $afectaciones['envio'] = $fiscal['afectaciones']['envio'];
                }
                AccesoFacturacion::cobro($actor, $cobro, 'comprobantes.emitir');
                $comprobante = $proceso->comprobante_id
                    ? Comprobante::findOrFail($proceso->comprobante_id)
                    : Comprobante::where('clave_idempotencia', $proceso->clave_emision)->first();
                $comprobante ??= app(EmitirComprobante::class)->emitir($actor, $cobro->id, $proceso->clave_emision, ['serieId' => $fiscal['serieId'], ...$fiscal['receptor']], $afectaciones, $fiscal['version']);
                ReglasFiscales::exigir($comprobante->cobro_id === $cobro->id && $comprobante->serie->tipoComprobante->codigo === $proceso->documento, 'El comprobante existente no corresponde al documento elegido. Revisa la emisión antes de imprimir.');
                $proceso->update(['comprobante_id' => $comprobante->id]);
            }
            $solicitud = ['tipo' => $proceso->documento === 'interno' ? 'ticket' : 'comprobante', 'id' => $proceso->comprobante_id ?? $cobro->id, 'caja' => $proceso->caja_id, 'area' => 0];
            $impresion = app(ImpresionOperativa::class);
            $plan = $impresion->previsualizar($actor, $solicitud);
            $trabajo = $impresion->imprimir($actor, $solicitud, 0, '', $proceso->clave_impresion, $plan['huella']);
            $proceso->update(['trabajo_impresion_id' => $trabajo->id]);
            if ($proceso->liberar) {
                app(CerrarAtencion::class)->cerrar($actor, $cobro->pedido_id, EstadoCuenta::huella($cobro->pedido->fresh()));
            }
            $proceso->update(['finalizado_at' => now(), 'error' => $trabajo->estado->value === 'enviado' ? null : 'El cobro está registrado. Revisa el estado de impresión y el papel antes de solicitar una copia.']);
        } catch (ValidationException $e) {
            $proceso->update(['error' => $e->validator->errors()->first()]);
        } catch (Throwable $e) {
            report($e);
            $proceso->update(['error' => 'Cobro registrado; no vuelvas a cobrar. Revisa la emisión o impresión pendiente y reintenta el proceso.']);
        }

        return $proceso->refresh();
    }
}
