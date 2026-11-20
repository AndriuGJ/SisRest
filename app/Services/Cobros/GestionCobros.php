<?php

namespace App\Services\Cobros;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\CobroDetalle;
use App\Models\MedioPago;
use App\Models\MotivoBeneficio;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\TipoDocumentoIdentidad;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Compras\ImportesCompra;
use App\Services\Inventario\CalculoProduccion;
use App\Services\Pos\AccesoPos;
use App\Services\Pos\TransaccionPos;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionCobros
{
    use TransaccionPos;

    /**
     * @param  array{clienteId:string,grupoPagador:string,incluirEnvio:bool,descuentoTipo?:string,descuentoValor?:string,motivoId?:string,motivoDetalle?:string}  $datos
     * @param  array<string,array{detalleId:string,cantidad:string}>  $seleccion
     * @param  array<string,array{medioId:string,monto:string,entregado:string,referencia:string}>  $pagos
     * @return array{huella:string,subtotal:numeric-string,descuentoProductos:numeric-string,descuentoGeneral:numeric-string,descuentoTipo:string|null,descuentoValor:numeric-string,motivoBeneficioId:int|null,motivoNombre:string|null,motivoDetalle:string|null,autorizadoId:int|null,autorizadoNombre:string|null,envio:numeric-string,total:numeric-string,efectivo:numeric-string,digital:numeric-string,vuelto:numeric-string,lineas:array<int,array{detalleId:int,descripcion:string,cantidad:numeric-string,precio:numeric-string,subtotal:numeric-string,total:numeric-string,descuentoProducto:numeric-string,descuentoGeneral:numeric-string,esCortesia:bool,beneficio:array<string,string|int|null>|null}>,pagos:array<int,array{medioId:int,nombre:string,efectivo:bool,monto:numeric-string,entregado:numeric-string,vuelto:numeric-string,referencia:string}>,cliente:array{id:int|null,nombre:string|null,tipoDocumento:string|null,documento:string|null,direccion:string|null},grupoPagador:string,caja:string,cajero:string}
     */
    public function previsualizar(User $actor, int $pedidoId, int $turnoId, array $datos, array $seleccion, array $pagos): array
    {
        return $this->ejecutar($actor, 'cobros.crear', fn () => $this->calcular($actor, $pedidoId, $turnoId, $datos, $seleccion, $pagos));
    }

    /**
     * @param  array{clienteId:string,grupoPagador:string,incluirEnvio:bool,descuentoTipo?:string,descuentoValor?:string,motivoId?:string,motivoDetalle?:string}  $datos
     * @param  array<string,array{detalleId:string,cantidad:string}>  $seleccion
     * @param  array<string,array{medioId:string,monto:string,entregado:string,referencia:string}>  $pagos
     */
    public function cobrar(User $actor, int $pedidoId, int $turnoId, string $clave, string $huella, array $datos, array $seleccion, array $pagos): Cobro
    {
        return $this->ejecutar($actor, 'cobros.crear', function () use ($actor, $pedidoId, $turnoId, $clave, $huella, $datos, $seleccion, $pagos): Cobro {
            AccesoPos::usuario($actor);
            Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
            if ($existente = Cobro::where('clave_idempotencia', $clave)->lockForUpdate()->first()) {
                abort_unless($existente->pedido_id === $pedidoId && $existente->usuario_id === $actor->id && $existente->turno_caja_id === $turnoId, 403);
                AccesoPos::pedido($actor, $existente->pedido);
                AccesoCajas::turno($actor, $existente->turnoCaja);

                return $existente;
            }
            $plan = $this->calcular($actor, $pedidoId, $turnoId, $datos, $seleccion, $pagos);
            if (! hash_equals($plan['huella'], $huella)) {
                throw ValidationException::withMessages(['seguridad' => 'El pedido, el cliente o los medios cambiaron desde la revisión. Vuelve a revisar el cobro.']);
            }
            $cobro = Cobro::create(['codigo' => 'COB-'.Str::ulid(), 'pedido_id' => $pedidoId, 'cliente_id' => $plan['cliente']['id'],
                'turno_caja_id' => $turnoId, 'usuario_id' => $actor->id, 'estado' => 'confirmado', 'moneda' => 'PEN', 'subtotal' => $plan['subtotal'],
                'descuento_productos' => $plan['descuentoProductos'], 'descuento_general' => $plan['descuentoGeneral'], 'descuento_tipo' => $plan['descuentoTipo'], 'descuento_valor' => $plan['descuentoValor'],
                'motivo_beneficio_id' => $plan['motivoBeneficioId'], 'autorizado_por' => $plan['autorizadoId'], 'beneficio_motivo_nombre' => $plan['motivoNombre'], 'beneficio_motivo_texto' => $plan['motivoDetalle'], 'beneficio_autorizado_nombre' => $plan['autorizadoNombre'],
                'cargo_envio' => $plan['envio'], 'total' => $plan['total'], 'cobrado_at' => now(), 'clave_idempotencia' => $clave,
                'grupo_pagador' => $plan['grupoPagador'] ?: null, 'cliente_nombre' => $plan['cliente']['nombre'], 'cliente_tipo_documento' => $plan['cliente']['tipoDocumento'],
                'cliente_numero_documento' => $plan['cliente']['documento'], 'cliente_direccion' => $plan['cliente']['direccion'], 'caja_nombre' => $plan['caja'], 'cajero_nombre' => $plan['cajero']]);
            foreach ($plan['lineas'] as $linea) {
                $detalle = PedidoDetalle::findOrFail($linea['detalleId']);
                $cobro->detalles()->create(['pedido_detalle_id' => $detalle->id, 'descripcion' => $linea['descripcion'], 'cantidad' => $linea['cantidad'], 'precio_unitario' => $linea['precio'], 'subtotal' => $linea['subtotal'], 'total' => $linea['total'], 'descuento' => bcadd($linea['descuentoProducto'], $linea['descuentoGeneral'], 2),
                    'descuento_producto' => $linea['descuentoProducto'], 'descuento_general' => $linea['descuentoGeneral'], 'es_cortesia' => $linea['esCortesia'], 'beneficio_resumen' => $linea['beneficio']]);
                $detalle->update(['cantidad_cobrada' => bcadd($detalle->cantidad_cobrada, $linea['cantidad'], 4)]);
                foreach ($detalle->hijos()->lockForUpdate()->get() as $hijo) {
                    if ($hijo->tipo_linea->value !== 'componente' || ! $hijo->factor_componente || bccomp($hijo->factor_componente, '0', 4) <= 0) {
                        throw ValidationException::withMessages(['seguridad' => 'El combo no tiene factores históricos válidos.']);
                    }
                    $nueva = bcadd($hijo->cantidad_cobrada, bcmul($linea['cantidad'], $hijo->factor_componente, 4), 4);
                    if (bccomp($nueva, bcsub($hijo->cantidad, $hijo->cantidad_anulada, 4), 4) > 0) {
                        throw ValidationException::withMessages(['seguridad' => 'La cantidad cobrada del combo supera sus componentes.']);
                    }
                    $hijo->update(['cantidad_cobrada' => $nueva]);
                }
            }
            foreach ($plan['pagos'] as $indice => $fila) {
                $pago = Pago::create(['cobro_id' => $cobro->id, 'medio_pago_id' => $fila['medioId'], 'medio_pago_nombre' => $fila['nombre'], 'afecta_efectivo' => $fila['efectivo'],
                    'monto' => $fila['monto'], 'monto_entregado' => $fila['entregado'], 'vuelto' => $fila['vuelto'], 'referencia' => $fila['referencia'] ?: null,
                    'recibido_at' => $cobro->cobrado_at, 'clave_idempotencia' => 'cobro:'.$cobro->id.':'.$indice]);
                if ($fila['efectivo']) {
                    MovimientoCaja::create(['turno_caja_id' => $turnoId, 'usuario_id' => $actor->id, 'tipo' => 'ingreso', 'concepto' => 'Cobro '.$cobro->codigo,
                        'monto' => $fila['monto'], 'motivo' => 'Importe aplicado después de devolver el vuelto.', 'origen_type' => Pago::class, 'origen_id' => $pago->id,
                        'registrado_at' => $cobro->cobrado_at, 'clave_idempotencia' => 'pago:'.$pago->id]);
                }
            }
            activity()->causedBy($actor)->performedOn($cobro)->event('cobro_confirmado')->withProperties(['pedido_id' => $pedidoId, 'turno_id' => $turnoId, 'total' => $plan['total'], 'efectivo' => $plan['efectivo'], 'digital' => $plan['digital']])->log('Cobro por productos y pagos combinados registrado');

            return $cobro;
        });
    }

    /**
     * @param  array{clienteId:string,grupoPagador:string,incluirEnvio:bool,descuentoTipo?:string,descuentoValor?:string,motivoId?:string,motivoDetalle?:string}  $datos
     * @param  array<string,array{detalleId:string,cantidad:string}>  $seleccion
     * @param  array<string,array{medioId:string,monto:string,entregado:string,referencia:string}>  $pagos
     * @return array{huella:string,subtotal:numeric-string,descuentoProductos:numeric-string,descuentoGeneral:numeric-string,descuentoTipo:string|null,descuentoValor:numeric-string,motivoBeneficioId:int|null,motivoNombre:string|null,motivoDetalle:string|null,autorizadoId:int|null,autorizadoNombre:string|null,envio:numeric-string,total:numeric-string,efectivo:numeric-string,digital:numeric-string,vuelto:numeric-string,lineas:array<int,array{detalleId:int,descripcion:string,cantidad:numeric-string,precio:numeric-string,subtotal:numeric-string,total:numeric-string,descuentoProducto:numeric-string,descuentoGeneral:numeric-string,esCortesia:bool,beneficio:array<string,string|int|null>|null}>,pagos:array<int,array{medioId:int,nombre:string,efectivo:bool,monto:numeric-string,entregado:numeric-string,vuelto:numeric-string,referencia:string}>,cliente:array{id:int|null,nombre:string|null,tipoDocumento:string|null,documento:string|null,direccion:string|null},grupoPagador:string,caja:string,cajero:string}
     */
    private function calcular(User $actor, int $pedidoId, int $turnoId, array $datos, array $seleccion, array $pagos): array
    {
        $datos += ['descuentoTipo' => '', 'descuentoValor' => '0', 'motivoId' => '', 'motivoDetalle' => ''];
        $actual = AccesoPos::usuario($actor);
        $turno = $this->turno($turnoId);
        AccesoCajas::turno($actual, $turno);
        Validator::make(['datos' => $datos, 'seleccion' => $seleccion, 'pagos' => $pagos], [
            'datos' => ['required', 'array:clienteId,grupoPagador,incluirEnvio,descuentoTipo,descuentoValor,motivoId,motivoDetalle'], 'datos.clienteId' => ['present', 'nullable', 'string', 'regex:/^[0-9]*$/', 'max:20'],
            'datos.grupoPagador' => ['present', 'nullable', 'string', 'max:100'], 'datos.incluirEnvio' => ['required', 'boolean'],
            'datos.descuentoTipo' => ['present', 'string', Rule::in(['', 'soles', 'porcentaje'])], 'datos.descuentoValor' => ['present', 'string', 'max:20'],
            'datos.motivoId' => ['present', 'nullable', 'integer'], 'datos.motivoDetalle' => ['present', 'nullable', 'string', 'max:500'],
            'seleccion' => ['array', 'max:500'], 'seleccion.*' => ['array:detalleId,cantidad'], 'seleccion.*.detalleId' => ['required', 'integer', 'distinct'],
            'seleccion.*.cantidad' => ['required', 'string', 'regex:/^[1-9][0-9]{0,3}$/'],
            'pagos' => ['array', 'max:10'], 'pagos.*' => ['array:medioId,monto,entregado,referencia'], 'pagos.*.medioId' => ['present', 'nullable', 'integer', 'distinct'],
            'pagos.*.monto' => ['present', 'string', 'max:20'], 'pagos.*.entregado' => ['present', 'nullable', 'string', 'max:20'], 'pagos.*.referencia' => ['present', 'nullable', 'string', 'max:150'],
        ])->validate();
        $lectura = Pedido::findOrFail($pedidoId);
        AccesoPos::pedido($actual, $lectura);
        if ($datos['clienteId'] !== (string) ($lectura->cliente_id ?? '')) {
            Gate::forUser($actual)->authorize('clientes.ver');
        }
        $medios = MedioPago::orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $motivos = MotivoBeneficio::orderBy('id')->lockForUpdate()->get();
        TipoDocumentoIdentidad::orderBy('id')->lockForUpdate()->get();
        $cliente = $datos['clienteId'] !== '' ? Cliente::with('tipoDocumento')->lockForUpdate()->findOrFail($datos['clienteId']) : null;
        if ($cliente && ! $cliente->activo && $cliente->id !== $lectura->cliente_id) {
            throw ValidationException::withMessages(['clienteId' => 'Selecciona un cliente activo.']);
        }
        $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);
        AccesoPos::pedido($actual, $pedido);
        if (in_array($pedido->estado->value, ['cerrado', 'anulado'], true)) {
            throw ValidationException::withMessages(['seguridad' => 'La atención ya terminó.']);
        }
        $detalles = $pedido->detalles()->with('hijos')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $cobros = $pedido->cobros()->orderBy('id')->lockForUpdate()->get();
        if ($cobros->contains('estado.value', 'borrador')) {
            throw ValidationException::withMessages(['seguridad' => 'Existe un cobro anterior en borrador. Revisa ese registro antes de continuar.']);
        }
        $lineas = [];
        $subtotal = $descuentoProductos = '0.00';
        $conBeneficios = false;
        foreach ($seleccion as $fila) {
            $detalle = $detalles->get((int) $fila['detalleId']);
            if (! $detalle || $detalle->detalle_padre_id || $detalle->estado->value !== 'confirmado') {
                throw ValidationException::withMessages(['seleccion' => 'Solo puedes cobrar productos raíz confirmados de este pedido.']);
            }
            $cobrada = '0';
            foreach (CobroDetalle::where('pedido_detalle_id', $detalle->id)->whereHas('cobro', fn ($q) => $q->where('estado', 'confirmado'))->lockForUpdate()->get() as $anterior) {
                $cobrada = bcadd($cobrada, $anterior->cantidad, 4);
            }
            if (bccomp($cobrada, $detalle->cantidad_cobrada, 4) !== 0) {
                throw ValidationException::withMessages(['seguridad' => 'El historial y la cantidad cobrada no coinciden. Revisa el pedido.']);
            }
            $cantidad = CalculoProduccion::cantidad($fila['cantidad']);
            $pendiente = bcsub(bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4), $detalle->cantidad_cobrada, 4);
            if (bccomp($cantidad, $pendiente, 4) > 0) {
                throw ValidationException::withMessages(['seleccion' => 'La cantidad supera lo pendiente de cobrar de '.$detalle->descripcion.'.']);
            }
            $importe = ImportesCompra::redondear(bcmul($cantidad, $detalle->precio_unitario, 6));
            $subtotal = ImportesCompra::redondear(bcadd($subtotal, $importe, 2));
            $beneficio = CalculoBeneficios::paraCantidad($detalle, $cantidad, true);
            $tiene = $detalle->es_cortesia || $detalle->descuento_tipo !== null || bccomp($detalle->descuento_importe, '0', 2) !== 0;
            $conBeneficios = $conBeneficios || $tiene;
            $descuentoProductos = bcadd($descuentoProductos, $beneficio, 2);
            $lineas[] = ['detalleId' => $detalle->id, 'descripcion' => $detalle->descripcion, 'cantidad' => $cantidad, 'precio' => $detalle->precio_unitario, 'subtotal' => $importe,
                'total' => bcsub($importe, $beneficio, 2), 'descuentoProducto' => $beneficio, 'descuentoGeneral' => '0.00', 'esCortesia' => $detalle->es_cortesia, 'beneficio' => $tiene ? CalculoBeneficios::instantanea($detalle) : null];
        }
        $envioPendiente = EstadoCuenta::envioPendiente($pedido, true);
        if (bccomp($envioPendiente, '0', 2) < 0) {
            throw ValidationException::withMessages(['seguridad' => 'El envío ya tiene importes cobrados superiores al cargo.']);
        }
        $envio = $datos['incluirEnvio'] ? $envioPendiente : '0.00';
        if ($lineas === [] && bccomp($envio, '0', 2) <= 0) {
            throw ValidationException::withMessages(['seleccion' => 'Selecciona productos pendientes o el cargo de envío.']);
        }
        $descuentoGeneral = '0.00';
        $motivo = null;
        if ($datos['descuentoTipo'] !== '') {
            Gate::forUser($actual)->authorize('descuentos.aplicar');
            if ($conBeneficios) {
                throw ValidationException::withMessages(['descuentoTipo' => 'Elige descuento general o beneficios por producto, sin acumularlos en este cobro.']);
            }
            $motivo = $motivos->firstWhere('id', (int) $datos['motivoId']);
            if (! $motivo || ! $motivo->activo || ! in_array($motivo->tipo->value, ['ambos', 'descuento'], true) || mb_strlen(trim($datos['motivoDetalle'])) < 5) {
                throw ValidationException::withMessages(['motivoDetalle' => 'Selecciona un motivo activo de descuento y explica la autorización con al menos cinco caracteres.']);
            }
            $descuentoGeneral = CalculoBeneficios::presupuesto($subtotal, $datos['descuentoTipo'], $datos['descuentoValor']);
            if (bccomp($descuentoGeneral, $subtotal, 2) === 0) {
                Gate::forUser($actual)->authorize('cortesias.aplicar');
            }
            $resto = $descuentoGeneral;
            $base = $subtotal;
            foreach ($lineas as &$fila) {
                $parte = bccomp($fila['subtotal'], '0', 2) > 0 ? CalculoBeneficios::repartir($resto, $fila['subtotal'], $base) : '0.00';
                $fila['descuentoGeneral'] = $parte;
                $fila['total'] = bcsub($fila['total'], $parte, 2);
                $resto = bcsub($resto, $parte, 2);
                $base = bcsub($base, $fila['subtotal'], 2);
            }
            unset($fila);
        }
        $total = ImportesCompra::redondear(bcadd(bcsub(bcsub($subtotal, $descuentoProductos, 2), $descuentoGeneral, 2), $envio, 2));
        $aplicado = $efectivo = $vuelto = '0.00';
        $pagosPlan = [];
        foreach ($pagos as $fila) {
            if ($fila['medioId'] === '' && $fila['monto'] === '' && $fila['entregado'] === '' && $fila['referencia'] === '') {
                continue;
            }
            $medio = $medios->get((int) $fila['medioId']);
            if (! $medio || ! $medio->activo) {
                throw ValidationException::withMessages(['pagos' => 'Selecciona medios de pago activos.']);
            }
            $monto = ImportesCompra::importe($fila['monto'], 'pagos', 2, true);
            $entregado = $fila['entregado'] === '' ? $monto : ImportesCompra::importe($fila['entregado'], 'pagos');
            if (bccomp($entregado, $monto, 2) < 0 || (! $medio->afecta_efectivo && bccomp($entregado, $monto, 2) !== 0)) {
                throw ValidationException::withMessages(['pagos' => 'El efectivo recibido debe cubrir lo aplicado. Los medios digitales no generan vuelto.']);
            }
            $referencia = trim($fila['referencia']);
            if (($medio->requiere_referencia || in_array(mb_strtolower($medio->codigo), ['yape', 'plin'], true)) && $referencia === '') {
                throw ValidationException::withMessages(['pagos' => 'Registra la referencia de '.$medio->nombre.'.']);
            }
            $cambio = bcsub($entregado, $monto, 2);
            $aplicado = bcadd($aplicado, $monto, 2);
            if ($medio->afecta_efectivo) {
                $efectivo = bcadd($efectivo, $monto, 2);
            }
            $vuelto = bcadd($vuelto, $cambio, 2);
            $pagosPlan[] = ['medioId' => $medio->id, 'nombre' => $medio->nombre, 'efectivo' => $medio->afecta_efectivo, 'monto' => $monto, 'entregado' => $entregado, 'vuelto' => $cambio, 'referencia' => $referencia];
        }
        if (bccomp($aplicado, $total, 2) !== 0) {
            throw ValidationException::withMessages(['pagos' => 'La suma de importes aplicados debe ser igual al total seleccionado: S/ '.$total.'.']);
        }
        ImportesCompra::redondear(bcadd($turno->efectivoEsperado(bloquear: true), $efectivo, 2));
        $plan = ['subtotal' => $subtotal, 'descuentoProductos' => $descuentoProductos, 'descuentoGeneral' => $descuentoGeneral,
            'descuentoTipo' => $motivo ? $datos['descuentoTipo'] : null, 'descuentoValor' => $motivo ? ImportesCompra::importe($datos['descuentoValor'], 'descuentoValor') : '0.00',
            'motivoBeneficioId' => $motivo?->id, 'motivoNombre' => $motivo?->nombre, 'motivoDetalle' => $motivo ? trim($datos['motivoDetalle']) : null,
            'autorizadoId' => $motivo ? $actual->id : null, 'autorizadoNombre' => $motivo ? $actual->name : null, 'envio' => $envio, 'total' => $total, 'efectivo' => $efectivo, 'digital' => bcsub($total, $efectivo, 2), 'vuelto' => $vuelto,
            'lineas' => $lineas, 'pagos' => $pagosPlan, 'cliente' => ['id' => $cliente?->id, 'nombre' => $cliente?->nombre, 'tipoDocumento' => $cliente?->tipoDocumento?->nombre, 'documento' => $cliente?->numero_documento, 'direccion' => $cliente?->direccion],
            'grupoPagador' => trim($datos['grupoPagador']), 'caja' => $turno->caja_nombre ?? $turno->caja->nombre, 'cajero' => $actual->name];
        $plan['huella'] = hash('sha256', json_encode([EstadoCuenta::huella($pedido), $turno->id, $plan, $medios->whereIn('id', array_column($pagosPlan, 'medioId'))->toArray(), $cliente?->getAttributes()], JSON_THROW_ON_ERROR));

        return $plan;
    }
}
