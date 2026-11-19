<?php

namespace App\Services\Pos;

use App\Models\AnulacionPedido;
use App\Models\Articulo;
use App\Models\CobroDetalle;
use App\Models\EnvioCocinaDetalle;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ReservaInventario;
use App\Models\User;
use App\Services\Cobros\CalculoBeneficios;
use App\Services\Cobros\EstadoCuenta;
use App\Services\Inventario\CalculoProduccion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GestionAnulaciones
{
    use TransaccionPos;

    /** @return array{huella:string,descripcion:string,cantidad:numeric-string,descuentoRetirado:numeric-string,hojas:list<array{detalleId:int,descripcion:string,reservaId:int,cantidad:numeric-string,liberada:numeric-string,merma:numeric-string,envios:list<array{id:int,antes:array<string,mixed>,despues:array{cantidad_anulada:numeric-string,cantidad_iniciada:numeric-string,cantidad_lista:numeric-string}}>}>} */
    public function previsualizar(User $actor, int $pedidoId, int $detalleId, string $cantidad, string $motivo): array
    {
        return $this->ejecutar($actor, 'pedidos.anular_enviados', fn () => $this->calcular($actor, $pedidoId, $detalleId, $cantidad, $motivo));
    }

    public function anular(User $actor, int $pedidoId, int $detalleId, string $cantidad, string $motivo, string $clave, string $huella): AnulacionPedido
    {
        return $this->ejecutar($actor, 'pedidos.anular_enviados', function () use ($actor, $pedidoId, $detalleId, $cantidad, $motivo, $clave, $huella): AnulacionPedido {
            AccesoPos::pedido($actor, Pedido::findOrFail($pedidoId));
            Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
            if ($existente = AnulacionPedido::where('clave_idempotencia', $clave)->lockForUpdate()->first()) {
                abort_unless($existente->pedido_id === $pedidoId && $existente->pedido_detalle_id === $detalleId && $existente->usuario_id === $actor->id, 403);

                return $existente;
            }
            $plan = $this->calcular($actor, $pedidoId, $detalleId, $cantidad, $motivo);
            if (! hash_equals((string) $plan['huella'], $huella)) {
                throw ValidationException::withMessages(['seguridad' => 'Cambió el pedido o el avance de Cocina. Revisa la anulación otra vez.']);
            }
            $registro = AnulacionPedido::create(['clave_idempotencia' => $clave, 'pedido_id' => $pedidoId, 'pedido_detalle_id' => $detalleId, 'usuario_id' => $actor->id, 'usuario_nombre' => User::findOrFail($actor->id)->name, 'cantidad' => $cantidad, 'motivo' => trim($motivo), 'anulado_at' => now()]);
            foreach ($plan['hojas'] as $fila) {
                $detalle = PedidoDetalle::findOrFail($fila['detalleId']);
                $reserva = ReservaInventario::findOrFail($fila['reservaId']);
                $articulo = Articulo::findOrFail($reserva->articulo_id);
                $saldo = bcsub($articulo->stock_actual, $fila['merma'], 4);
                if (bccomp($fila['merma'], '0', 4) > 0) {
                    MovimientoInventario::create(['articulo_id' => $articulo->id, 'usuario_id' => $actor->id, 'tipo' => 'merma', 'cantidad' => bcsub('0', $fila['merma'], 4),
                        'saldo_anterior' => $articulo->stock_actual, 'saldo_posterior' => $saldo, 'costo_unitario' => $articulo->costo_promedio,
                        'motivo' => trim($motivo), 'clave_idempotencia' => 'anulacion:'.$registro->id.':'.$detalle->id, 'registrado_at' => now(), 'origen_type' => AnulacionPedido::class, 'origen_id' => $registro->id]);
                }
                $articulo->update(['stock_actual' => $saldo, 'stock_reservado' => bcsub($articulo->stock_reservado, $fila['cantidad'], 4)]);
                $reserva->update(['cantidad_liberada' => bcadd($reserva->cantidad_liberada, $fila['liberada'], 4), 'cantidad_consumida' => bcadd($reserva->cantidad_consumida, $fila['merma'], 4)]);
                $detalle->update(['cantidad_anulada' => bcadd($detalle->cantidad_anulada, $fila['cantidad'], 4)]);
                foreach ($fila['envios'] as $envio) {
                    EnvioCocinaDetalle::findOrFail($envio['id'])->update($envio['despues']);
                }
                $registro->detalles()->create(['pedido_detalle_id' => $detalle->id, 'articulo_id' => $articulo->id, 'descripcion' => $detalle->descripcion,
                    'cantidad_liberada' => $fila['liberada'], 'cantidad_merma' => $fila['merma'], 'avance_antes' => array_column($fila['envios'], 'antes'), 'avance_despues' => array_column($fila['envios'], 'despues')]);
            }
            $raiz = PedidoDetalle::findOrFail($detalleId);
            if ($raiz->hijos()->exists()) {
                $raiz->update(['cantidad_anulada' => bcadd($raiz->cantidad_anulada, $plan['cantidad'], 4)]);
            }
            $raiz->update(['descuento_importe' => bcsub($raiz->descuento_importe, $plan['descuentoRetirado'], 2)]);
            EstadoAtencion::actualizar(Pedido::findOrFail($pedidoId));
            activity()->causedBy($actor)->performedOn($registro)->event('productos_anulados')->withProperties(['pedido_id' => $pedidoId, 'cantidad' => $cantidad, 'descuento_retirado' => $plan['descuentoRetirado']])->log('Anulación con liberación de reserva y merma autorizada cuando corresponde');

            return $registro;
        });
    }

    /** @return array{huella:string,descripcion:string,cantidad:numeric-string,descuentoRetirado:numeric-string,hojas:list<array{detalleId:int,descripcion:string,reservaId:int,cantidad:numeric-string,liberada:numeric-string,merma:numeric-string,envios:list<array{id:int,antes:array<string,mixed>,despues:array{cantidad_anulada:numeric-string,cantidad_iniciada:numeric-string,cantidad_lista:numeric-string}}>}>} */
    private function calcular(User $actor, int $pedidoId, int $detalleId, string $cantidad, string $motivo): array
    {
        $actual = AccesoPos::usuario($actor);
        Validator::make(['cantidad' => $cantidad, 'motivo' => trim($motivo)], ['cantidad' => ['required', 'regex:/^[1-9][0-9]{0,3}$/'], 'motivo' => ['required', 'string', 'min:5', 'max:500']])->validate();
        $valor = CalculoProduccion::cantidad($cantidad);
        $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);
        AccesoPos::pedido($actual, $pedido);
        $raiz = $pedido->detalles()->with(['hijos.producto', 'producto'])->whereNull('detalle_padre_id')->lockForUpdate()->findOrFail($detalleId);
        if (in_array($pedido->estado->value, ['cerrado', 'anulado'], true) || $raiz->estado->value !== 'confirmado' || ! $raiz->confirmacion_pedido_id) {
            throw ValidationException::withMessages(['seguridad' => 'Solo se anulan productos confirmados de una atención vigente.']);
        }
        $pagada = '0';
        foreach (CobroDetalle::where('pedido_detalle_id', $raiz->id)->whereHas('cobro', fn ($q) => $q->where('estado', 'confirmado'))->lockForUpdate()->get() as $linea) {
            $pagada = bcadd($pagada, $linea->cantidad, 4);
        }
        if (bccomp($pagada, $raiz->cantidad_cobrada, 4) !== 0) {
            throw ValidationException::withMessages(['seguridad' => 'El historial y las cantidades cobradas no coinciden.']);
        }
        $maximo = bcsub(bcsub($raiz->cantidad, $raiz->cantidad_anulada, 4), bccomp($raiz->cantidad_cobrada, $raiz->cantidad_entregada, 4) > 0 ? $raiz->cantidad_cobrada : $raiz->cantidad_entregada, 4);
        if (bccomp($valor, $maximo, 4) > 0) {
            throw ValidationException::withMessages(['cantidad' => 'La cantidad supera lo no cobrado y no entregado. Los consumos liquidados requieren devolución.']);
        }
        $hojas = $raiz->producto->tipo->value === 'combo' ? $raiz->hijos : collect([$raiz]);
        if ($hojas->isEmpty()) {
            throw ValidationException::withMessages(['seguridad' => 'El combo no tiene sus componentes históricos.']);
        }
        $plan = [];
        $snapshots = [];
        foreach ($hojas as $hoja) {
            if ($hoja->estado->value !== 'confirmado' || ($hoja->id !== $raiz->id && (! $hoja->factor_componente || bccomp($hoja->factor_componente, '0', 4) <= 0))) {
                throw ValidationException::withMessages(['seguridad' => 'Revisa las cantidades históricas del producto.']);
            }
            $cantidadHoja = $hoja->id === $raiz->id ? $valor : CalculoProduccion::cantidad(bcmul($valor, $hoja->factor_componente, 4));
            $pendiente = bcsub(bcsub($hoja->cantidad, $hoja->cantidad_anulada, 4), bccomp($hoja->cantidad_cobrada, $hoja->cantidad_entregada, 4) > 0 ? $hoja->cantidad_cobrada : $hoja->cantidad_entregada, 4);
            if (bccomp($cantidadHoja, $pendiente, 4) > 0) {
                throw ValidationException::withMessages(['cantidad' => 'Un componente del combo ya fue cobrado o entregado. No se puede anular esa cantidad.']);
            }
            $reservas = $hoja->reservas()->lockForUpdate()->get();
            $reserva = $reservas->first();
            if ($reservas->count() !== 1 || ! $reserva || bccomp(bcsub(bcsub($reserva->cantidad, $reserva->cantidad_consumida, 4), $reserva->cantidad_liberada, 4), $cantidadHoja, 4) < 0) {
                throw ValidationException::withMessages(['seguridad' => 'La reserva no cubre la anulación.']);
            }
            $envios = EnvioCocinaDetalle::where('pedido_detalle_id', $hoja->id)->orderBy('id')->lockForUpdate()->get();
            if (($hoja->area_preparacion_id && $envios->count() !== 1) || (! $hoja->area_preparacion_id && $envios->isNotEmpty())) {
                throw ValidationException::withMessages(['seguridad' => 'El envío no coincide con el destino histórico del producto.']);
            }
            $merma = '0.0000';
            $cambios = [];
            foreach ($envios as $envio) {
                $calculo = CalculoAnulacion::envio($envio, $cantidadHoja);
                $merma = $calculo['merma'];
                $despues = $calculo['despues'];
                $cambios[] = ['id' => $envio->id, 'antes' => $envio->only(['cantidad_anulada', 'cantidad_iniciada', 'cantidad_lista', 'cantidad_entregada']), 'despues' => $despues];
            }
            if (bccomp($merma, '0', 4) > 0) {
                Gate::forUser($actual)->authorize('inventario.registrar_merma');
            }
            $articulo = Articulo::findOrFail($reserva->articulo_id);
            if (bccomp($articulo->stock_reservado, $cantidadHoja, 4) < 0 || bccomp($articulo->stock_actual, $merma, 4) < 0) {
                throw ValidationException::withMessages(['seguridad' => 'Las existencias no coinciden con la reserva.']);
            }
            $plan[] = ['detalleId' => $hoja->id, 'descripcion' => $hoja->descripcion, 'reservaId' => $reserva->id, 'cantidad' => $cantidadHoja, 'liberada' => bcsub($cantidadHoja, $merma, 4), 'merma' => $merma, 'envios' => $cambios];
            $snapshots[] = [$hoja->getAttributes(), $reserva->getAttributes(), $envios->toArray(), $articulo->only(['stock_actual', 'stock_reservado'])];
        }
        $descuento = CalculoBeneficios::paraCantidad($raiz, $valor, true);
        $huella = hash('sha256', json_encode([EstadoCuenta::huella($pedido), $snapshots, $cantidad, trim($motivo)], JSON_THROW_ON_ERROR));

        return ['huella' => $huella, 'descripcion' => $raiz->descripcion, 'cantidad' => $valor, 'descuentoRetirado' => $descuento, 'hojas' => $plan];
    }
}
