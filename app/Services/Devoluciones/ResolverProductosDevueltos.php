<?php

namespace App\Services\Devoluciones;

use App\Models\Articulo;
use App\Models\Devolucion;
use App\Models\DevolucionDetalle;
use App\Models\EnvioCocinaDetalle;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ReservaInventario;
use App\Models\ResolucionDevolucion;
use App\Models\ResolucionDevolucionDetalle;
use App\Models\User;
use App\Services\Inventario\CalculoProduccion;
use App\Services\Pos\CalculoAnulacion;
use App\Services\Pos\EstadoAtencion;
use App\Services\Pos\TransaccionPos;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type EnvioCambio array{id:int,antes:array<string,mixed>,despues:array{cantidad_anulada:numeric-string,cantidad_iniciada:numeric-string,cantidad_lista:numeric-string}}
 * @phpstan-type Lote array{movimientoId:int,cantidad:numeric-string,costo:numeric-string}
 * @phpstan-type Hoja array{detalleId:int,articuloId:int,descripcion:string,cantidad:numeric-string,destino:string,reservaId:int|null,liberada:numeric-string,merma:numeric-string,costo:numeric-string,envios:list<EnvioCambio>,lotes:list<Lote>}
 * @phpstan-type Plan array{huella:string,pedidoId:int,raizId:int,cantidad:numeric-string,accion:string,motivo:string,hojas:list<Hoja>}
 */
class ResolverProductosDevueltos
{
    use TransaccionPos;

    /**
     * @param  array<int,string>  $destinos  Clave: pedido_detalle_id de cada hoja.
     * @return Plan
     */
    public function previsualizar(User $actor, int $detalleId, string $cantidad, string $accion, array $destinos, string $motivo): array
    {
        return $this->ejecutar($actor, 'devoluciones.crear', fn () => $this->calcular($actor, $detalleId, $cantidad, $accion, $destinos, $motivo));
    }

    /** @param array<int,string> $destinos */
    public function resolver(User $actor, int $detalleId, string $cantidad, string $accion, array $destinos, string $motivo, string $clave, string $huella): ResolucionDevolucion
    {
        return $this->ejecutar($actor, 'devoluciones.crear', function () use ($actor, $detalleId, $cantidad, $accion, $destinos, $motivo, $clave, $huella): ResolucionDevolucion {
            $detalle = DevolucionDetalle::findOrFail($detalleId);
            AccesoDevoluciones::devolucion($actor, $detalle->devolucion);
            Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
            if ($existente = ResolucionDevolucion::where('clave_idempotencia', $clave)->lockForUpdate()->first()) {
                abort_unless($existente->devolucion_detalle_id === $detalleId && $existente->usuario_id === $actor->id, 403);

                return $existente;
            }
            $plan = $this->calcular($actor, $detalleId, $cantidad, $accion, $destinos, $motivo);
            if (! hash_equals($plan['huella'], $huella)) {
                throw ValidationException::withMessages(['seguridad' => 'Cambió el avance, el inventario o la devolución. Revisa otra vez los productos.']);
            }
            $registro = ResolucionDevolucion::create(['devolucion_detalle_id' => $detalleId, 'usuario_id' => $actor->id, 'usuario_nombre' => User::findOrFail($actor->id)->name, 'cantidad' => $plan['cantidad'], 'accion' => $plan['accion'], 'motivo' => $plan['motivo'], 'clave_idempotencia' => $clave, 'resuelta_at' => now()]);
            foreach ($plan['hojas'] as $fila) {
                $hoja = PedidoDetalle::findOrFail($fila['detalleId']);
                $articulo = Articulo::findOrFail($fila['articuloId']);
                if ($plan['accion'] === 'cancelar_pendiente') {
                    $reserva = ReservaInventario::findOrFail($fila['reservaId']);
                    if (bccomp($fila['merma'], '0', 4) > 0) {
                        $this->movimiento($actor, $registro, $articulo, bcsub('0', $fila['merma'], 4), $fila['costo'], 'merma', 'cancelar:'.$hoja->id, false);
                    }
                    $articulo->update(['stock_actual' => bcsub($articulo->stock_actual, $fila['merma'], 4), 'stock_reservado' => bcsub($articulo->stock_reservado, $fila['cantidad'], 4)]);
                    $reserva->update(['cantidad_liberada' => bcadd($reserva->cantidad_liberada, $fila['liberada'], 4), 'cantidad_consumida' => bcadd($reserva->cantidad_consumida, $fila['merma'], 4)]);
                    foreach ($fila['envios'] as $envio) {
                        EnvioCocinaDetalle::findOrFail($envio['id'])->update($envio['despues']);
                    }
                    $hoja->update(['cantidad_cancelada_pagada' => bcadd($hoja->cantidad_cancelada_pagada, $fila['cantidad'], 4)]);
                    $registro->detalles()->create(['pedido_detalle_id' => $hoja->id, 'articulo_id' => $articulo->id, 'descripcion' => $fila['descripcion'], 'destino' => 'cancelar_pendiente', 'cantidad' => $fila['cantidad'], 'costo_unitario' => $fila['costo'], 'avance_antes' => array_column($fila['envios'], 'antes'), 'avance_despues' => array_column($fila['envios'], 'despues')]);
                } elseif ($plan['accion'] === 'retorno') {
                    if ($fila['destino'] === 'reingreso') {
                        $valor = '0';
                        foreach ($fila['lotes'] as $lote) {
                            $valor = bcadd($valor, bcmul($lote['cantidad'], $lote['costo'], 10), 10);
                        }
                        $saldo = CalculoProduccion::cantidad(bcadd($articulo->stock_actual, $fila['cantidad'], 4));
                        $costo = CalculoProduccion::costo(bcdiv(bcadd(bcmul($articulo->stock_actual, $articulo->costo_promedio, 10), $valor, 10), $saldo, 12));
                        foreach ($fila['lotes'] as $lote) {
                            $this->movimiento($actor, $registro, $articulo, $lote['cantidad'], $lote['costo'], 'reingreso', 'retorno:'.$hoja->id.':'.$lote['movimientoId']);
                        }
                        $articulo->update(['costo_promedio' => $costo]);
                    }
                    // La entrega ya consumió el stock: merma/sin reingreso no lo descuentan otra vez.
                    foreach ($fila['lotes'] as $lote) {
                        $registro->detalles()->create(['pedido_detalle_id' => $hoja->id, 'articulo_id' => $articulo->id, 'movimiento_entrega_id' => $lote['movimientoId'], 'descripcion' => $fila['descripcion'], 'destino' => $fila['destino'], 'cantidad' => $lote['cantidad'], 'costo_unitario' => $lote['costo']]);
                    }
                    $hoja->update(['cantidad_devuelta' => bcadd($hoja->cantidad_devuelta, $fila['cantidad'], 4)]);
                }
            }
            if ($plan['accion'] !== 'mantener' && ! collect($plan['hojas'])->contains('detalleId', $plan['raizId'])) {
                $raiz = PedidoDetalle::findOrFail($plan['raizId']);
                $campo = $plan['accion'] === 'retorno' ? 'cantidad_devuelta' : 'cantidad_cancelada_pagada';
                $raiz->update([$campo => bcadd($raiz->$campo, $plan['cantidad'], 4)]);
            }
            $detalle = DevolucionDetalle::findOrFail($detalleId);
            $detalle->update(['cantidad_resuelta' => bcadd($detalle->cantidad_resuelta, $plan['cantidad'], 4)]);
            $pedido = Pedido::findOrFail($plan['pedidoId']);
            if (! in_array($pedido->estado->value, ['cerrado', 'anulado'], true)) {
                EstadoAtencion::actualizar($pedido);
            }
            activity()->causedBy($actor)->performedOn($registro)->event('productos_devolucion_resueltos')->withProperties(['devolucion_id' => $detalle->devolucion_id, 'accion' => $plan['accion'], 'cantidad' => $plan['cantidad']])->log('Productos resueltos por separado del dinero devuelto');

            return $registro;
        });
    }

    /**
     * @param  numeric-string  $cantidad
     * @param  numeric-string  $costo
     */
    private function movimiento(User $actor, ResolucionDevolucion $registro, Articulo $articulo, string $cantidad, string $costo, string $tipo, string $sufijo, bool $actualizar = true): void
    {
        $saldo = bcadd($articulo->stock_actual, $cantidad, 4);
        MovimientoInventario::create(['articulo_id' => $articulo->id, 'usuario_id' => $actor->id, 'tipo' => $tipo, 'cantidad' => $cantidad, 'saldo_anterior' => $articulo->stock_actual, 'saldo_posterior' => $saldo, 'costo_unitario' => $costo, 'motivo' => $registro->motivo, 'clave_idempotencia' => 'resolucion:'.$registro->id.':'.$sufijo, 'registrado_at' => $registro->resuelta_at, 'origen_type' => ResolucionDevolucion::class, 'origen_id' => $registro->id]);
        if ($actualizar) {
            $articulo->update(['stock_actual' => $saldo]);
        }
    }

    /**
     * @param  array<int,string>  $destinos
     * @return Plan
     */
    private function calcular(User $actor, int $detalleId, string $cantidad, string $accion, array $destinos, string $motivo): array
    {
        $actual = AccesoDevoluciones::usuario($actor);
        $motivo = trim($motivo);
        Validator::make(compact('cantidad', 'accion', 'destinos', 'motivo'), ['cantidad' => ['required', 'string', 'regex:/^[1-9][0-9]{0,3}$/'], 'accion' => ['required', Rule::in(['mantener', 'cancelar_pendiente', 'retorno'])], 'destinos' => ['array', 'max:100'], 'destinos.*' => ['string', Rule::in(['sin_reingreso', 'reingreso', 'merma'])], 'motivo' => ['required', 'string', 'min:5', 'max:500']])->validate();
        $valor = CalculoProduccion::cantidad($cantidad);
        $lectura = DevolucionDetalle::findOrFail($detalleId);
        $pedido = Pedido::lockForUpdate()->findOrFail($lectura->devolucion->cobro->pedido_id);
        $devolucion = Devolucion::lockForUpdate()->findOrFail($lectura->devolucion_id);
        AccesoDevoluciones::devolucion($actual, $devolucion);
        $detalle = DevolucionDetalle::lockForUpdate()->findOrFail($detalleId);
        if ($devolucion->estado->value !== 'confirmada' || bccomp($valor, bcsub($detalle->cantidad, $detalle->cantidad_resuelta, 4), 4) > 0 || $detalle->destino_producto->value !== 'sin_reingreso') {
            throw ValidationException::withMessages(['cantidad' => 'La cantidad supera los productos devueltos aún sin resolver o su historial requiere conciliación.']);
        }
        $raiz = PedidoDetalle::with(['hijos.producto', 'producto'])->lockForUpdate()->findOrFail($detalle->detalleCobro->pedido_detalle_id);
        abort_unless($raiz->pedido_id === $pedido->id && ! $raiz->detalle_padre_id, 422);
        if ($accion === 'cancelar_pendiente') {
            Gate::forUser($actual)->authorize('pedidos.anular_enviados');
            if (in_array($pedido->estado->value, ['cerrado', 'anulado'], true)) {
                throw ValidationException::withMessages(['accion' => 'La atención terminada no tiene entregas pendientes que cancelar.']);
            }
        }
        $hojas = $raiz->producto->tipo->value === 'combo' ? $raiz->hijos : collect([$raiz]);
        if ($hojas->isEmpty()) {
            throw ValidationException::withMessages(['seguridad' => 'Faltan los componentes históricos del combo.']);
        }
        $plan = [];
        $snapshots = [$pedido->getAttributes(), $devolucion->getAttributes(), $detalle->getAttributes(), $raiz->getAttributes(), $detalle->resoluciones()->with('detalles')->orderBy('id')->get()->toArray()];
        if ($accion !== 'mantener' && bccomp($valor, bcsub(bcsub($raiz->cantidad_cobrada, $raiz->cantidad_cancelada_pagada, 4), $raiz->cantidad_devuelta, 4), 4) > 0) {
            throw ValidationException::withMessages(['cantidad' => 'Supera los productos pagados sin cancelación o retorno físico.']);
        }
        foreach ($hojas as $hoja) {
            if ($hoja->id !== $raiz->id && (! $hoja->factor_componente || bccomp($hoja->factor_componente, '0', 4) <= 0)) {
                throw ValidationException::withMessages(['seguridad' => 'El combo no tiene factores históricos válidos.']);
            }
            $q = $hoja->id === $raiz->id ? $valor : CalculoProduccion::cantidad(bcmul($valor, $hoja->factor_componente, 4));
            $reservas = $hoja->reservas()->lockForUpdate()->get();
            $reserva = $reservas->first();
            if ($reservas->count() !== 1 || ! $reserva) {
                throw ValidationException::withMessages(['seguridad' => 'Falta la reserva histórica del producto.']);
            }
            $articulo = Articulo::with('unidadMedida')->findOrFail($reserva->articulo_id);
            $snapshots[] = [$hoja->getAttributes(), $reservas->toArray(), $articulo->getAttributes(), $articulo->unidadMedida->getAttributes()];
            $merma = $liberada = '0.0000';
            $envios = $lotes = [];
            $destino = $accion;
            if ($accion !== 'mantener' && bccomp($q, bcsub(bcsub($hoja->cantidad_cobrada, $hoja->cantidad_cancelada_pagada, 4), $hoja->cantidad_devuelta, 4), 4) > 0) {
                throw ValidationException::withMessages(['cantidad' => 'Un componente ya fue devuelto o cancelado.']);
            }
            if ($accion === 'cancelar_pendiente') {
                if (bccomp($q, bcsub($hoja->cantidadVigente(), $hoja->cantidad_entregada, 4), 4) > 0 || bccomp($q, bcsub(bcsub($reserva->cantidad, $reserva->cantidad_consumida, 4), $reserva->cantidad_liberada, 4), 4) > 0) {
                    throw ValidationException::withMessages(['cantidad' => 'Solo puedes cancelar cantidades no entregadas y todavía reservadas.']);
                }
                $avances = EnvioCocinaDetalle::where('pedido_detalle_id', $hoja->id)->orderBy('id')->lockForUpdate()->get();
                if (($hoja->area_preparacion_id && $avances->count() !== 1) || (! $hoja->area_preparacion_id && $avances->isNotEmpty())) {
                    throw ValidationException::withMessages(['seguridad' => 'El destino histórico y el envío a Cocina no coinciden.']);
                }
                foreach ($avances as $avance) {
                    $cambio = CalculoAnulacion::envio($avance, $q);
                    $merma = $cambio['merma'];
                    $envios[] = ['id' => $avance->id, 'antes' => $avance->only(['cantidad_anulada', 'cantidad_iniciada', 'cantidad_lista', 'cantidad_entregada']), 'despues' => $cambio['despues']];
                }
                $liberada = bcsub($q, $merma, 4);
                if (bccomp($merma, '0', 4) > 0) {
                    Gate::forUser($actual)->authorize('inventario.registrar_merma');
                }
                if (bccomp($articulo->stock_reservado, $q, 4) < 0 || bccomp($articulo->stock_actual, $merma, 4) < 0) {
                    throw ValidationException::withMessages(['seguridad' => 'El inventario no cubre la cancelación pendiente.']);
                }
                $snapshots[] = $avances->toArray();
            } elseif ($accion === 'retorno') {
                $destino = $destinos[$hoja->id] ?? '';
                if (! in_array($destino, ['sin_reingreso', 'reingreso', 'merma'], true) || bccomp($q, bcsub($hoja->cantidad_entregada, $hoja->cantidad_devuelta, 4), 4) > 0) {
                    throw ValidationException::withMessages(['destinos' => 'Selecciona el destino de cada componente y no superes la cantidad entregada sin retorno.']);
                }
                if ($destino === 'reingreso') {
                    Gate::forUser($actual)->authorize('inventario.ajustar');
                    if ($articulo->tipo->value !== 'reventa' || ! $articulo->activo || ! $articulo->unidadMedida->activo) {
                        throw ValidationException::withMessages(['destinos' => 'Solo reingresan artículos de reventa activos cuya condición permita volver a venderlos. Los preparados no recuperan disponibilidad.']);
                    }
                    CalculoProduccion::cantidad(bcadd($articulo->stock_actual, $q, 4));
                } elseif ($destino === 'merma') {
                    Gate::forUser($actual)->authorize('inventario.registrar_merma');
                }
                $movimientos = MovimientoInventario::where('origen_type', PedidoDetalle::class)->where('origen_id', $hoja->id)->where('tipo', 'entrega')->orderBy('id')->lockForUpdate()->get();
                $asignaciones = ResolucionDevolucionDetalle::whereIn('movimiento_entrega_id', $movimientos->pluck('id'))->orderBy('id')->lockForUpdate()->get();
                $snapshots[] = [$movimientos->toArray(), $asignaciones->toArray()];
                $resto = $q;
                $totalEntregado = '0';
                foreach ($movimientos as $movimiento) {
                    if ($movimiento->articulo_id !== $articulo->id || bccomp($movimiento->cantidad, '0', 4) >= 0) {
                        throw ValidationException::withMessages(['seguridad' => 'La salida histórica no coincide con el artículo.']);
                    }
                    $original = bcsub('0', $movimiento->cantidad, 4);
                    $totalEntregado = bcadd($totalEntregado, $original, 4);
                    $disponible = $original;
                    foreach ($asignaciones->where('movimiento_entrega_id', $movimiento->id) as $asignada) {
                        $disponible = bcsub($disponible, $asignada->cantidad, 4);
                    }
                    if (bccomp($disponible, '0', 4) < 0) {
                        throw ValidationException::withMessages(['seguridad' => 'La salida ya tiene retornos superiores a su cantidad.']);
                    }
                    $tomar = bccomp($disponible, $resto, 4) < 0 ? $disponible : $resto;
                    if (bccomp($tomar, '0', 4) > 0) {
                        $lotes[] = ['movimientoId' => $movimiento->id, 'cantidad' => $tomar, 'costo' => $movimiento->costo_unitario];
                        $resto = bcsub($resto, $tomar, 4);
                    }
                }
                if (bccomp($resto, '0', 4) !== 0 || bccomp($totalEntregado, $hoja->cantidad_entregada, 4) !== 0) {
                    throw ValidationException::withMessages(['seguridad' => 'No existe una salida de inventario conciliada para esta cantidad devuelta.']);
                }
            }
            if ($accion !== 'mantener') {
                $plan[] = ['detalleId' => $hoja->id, 'articuloId' => $articulo->id, 'descripcion' => $hoja->descripcion, 'cantidad' => $q, 'destino' => $destino, 'reservaId' => $reserva->id, 'liberada' => $liberada, 'merma' => $merma, 'costo' => $articulo->costo_promedio, 'envios' => $envios, 'lotes' => $lotes];
            }
        }
        $resultado = ['pedidoId' => $pedido->id, 'raizId' => $raiz->id, 'cantidad' => $valor, 'accion' => $accion, 'motivo' => trim($motivo), 'hojas' => $plan];
        $resultado['huella'] = hash('sha256', json_encode([$snapshots, $resultado], JSON_THROW_ON_ERROR));

        return $resultado;
    }
}
