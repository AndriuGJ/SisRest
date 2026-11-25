<?php

namespace App\Services\Pos;

use App\Models\AnulacionPedido;
use App\Models\Articulo;
use App\Models\CobroDetalle;
use App\Models\EnvioCocinaDetalle;
use App\Models\MovimientoInventario;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ReservaInventario;
use App\Models\User;
use App\Services\Cobros\CalculoBeneficios;
use App\Services\Cobros\EstadoCuenta;
use App\Services\Inventario\CalculoProduccion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GestionAnulaciones
{
    use TransaccionPos;

    /** @return array{huella:string,descripcion:string,cantidad:numeric-string,descuentoRetirado:numeric-string,hojas:list<array{detalleId:int,descripcion:string,reservaId:int,cantidad:numeric-string,liberada:numeric-string,merma:numeric-string,envios:list<array{id:int,antes:array<string,mixed>,despues:array{cantidad_anulada:numeric-string,cantidad_iniciada:numeric-string,cantidad_lista:numeric-string}}>}>} */
    public function previsualizar(User $actor, int $pedidoId, int $detalleId, string $cantidad, string $motivo): array
    {
        return $this->ejecutar($actor, 'pedidos.anular_enviados', fn () => $this->calcular($actor, $pedidoId, $detalleId, $cantidad, $motivo));
    }

    public function maximoAnulable(User $actor, int $pedidoId, int $detalleId): int
    {
        $actual = AccesoPos::usuario($actor);
        Gate::forUser($actual)->authorize('pedidos.anular_enviados');
        $pedido = Pedido::findOrFail($pedidoId);
        AccesoPos::pedido($actual, $pedido);
        $raiz = $pedido->detalles()->with('hijos')->whereNull('detalle_padre_id')->findOrFail($detalleId);
        if ($raiz->estado->value !== 'confirmado' || in_array($pedido->estado->value, ['cerrado', 'anulado'], true)) {
            return 0;
        }
        $maximo = (int) $this->pendiente($raiz);
        foreach ($raiz->hijos as $hoja) {
            if (! $hoja->factor_componente || bccomp($hoja->factor_componente, '0', 4) <= 0) {
                return 0;
            }
            $maximo = min($maximo, (int) bcdiv($this->pendiente($hoja), $hoja->factor_componente, 0));
        }

        return max(0, min(9999, $maximo));
    }

    /** @return numeric-string */
    private function pendiente(PedidoDetalle $detalle): string
    {
        return bcsub(bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4), bccomp($detalle->cantidad_cobrada, $detalle->cantidad_entregada, 4) > 0 ? $detalle->cantidad_cobrada : $detalle->cantidad_entregada, 4);
    }

    public function anular(User $actor, int $pedidoId, int $detalleId, string $cantidad, string $motivo, string $clave, string $huella, int|string $motivoId = '', #[\SensitiveParameter] string $claveAutorizacion = ''): AnulacionPedido
    {
        $this->autorizar($actor, $pedidoId, $motivoId, $motivo, $claveAutorizacion);

        return $this->ejecutar($actor, 'pedidos.anular_enviados', function () use ($actor, $pedidoId, $detalleId, $cantidad, $motivo, $clave, $huella, $motivoId): AnulacionPedido {
            $this->motivoVigente($motivoId, $motivo);
            AccesoPos::pedido($actor, Pedido::findOrFail($pedidoId));
            Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
            if ($existente = AnulacionPedido::where('clave_idempotencia', $clave)->lockForUpdate()->first()) {
                abort_unless($existente->pedido_id === $pedidoId && $existente->pedido_detalle_id === $detalleId && $existente->usuario_id === $actor->id, 403);

                return $existente;
            }
            $plan = $this->calcular($actor, $pedidoId, $detalleId, $cantidad, $motivo);
            if (! hash_equals($plan['huella'], $huella)) {
                throw ValidationException::withMessages(['seguridad' => 'Cambió el pedido o el avance de Cocina. Revisa la anulación otra vez.']);
            }

            return $this->aplicar($actor, $pedidoId, $detalleId, $cantidad, $motivo, $clave, $plan);
        });
    }

    private function autorizar(User $actor, int $pedidoId, int|string $motivoId, string $motivo, #[\SensitiveParameter] string $claveAutorizacion): void
    {
        $actual = AccesoPos::usuario($actor);
        Gate::forUser($actual)->authorize('pedidos.anular_enviados');
        AccesoPos::pedido($actual, Pedido::findOrFail($pedidoId));
        // Fuera de la transacción: un rechazo no revierte el contador de intentos.
        app(SeguridadAnulaciones::class)->autorizarAnulacion($actual, $motivoId, $motivo, $claveAutorizacion);
    }

    private function motivoVigente(int|string $motivoId, string $motivo): void
    {
        $actual = app(SeguridadAnulaciones::class)->motivoActivo($motivoId, bloquear: true);
        if (! hash_equals($actual->nombre, $motivo)) {
            throw ValidationException::withMessages(['motivoId' => 'El motivo cambió. Revisa la anulación nuevamente.']);
        }
    }

    /** @param array{cantidad:numeric-string,descuentoRetirado:numeric-string,hojas:list<array{detalleId:int,reservaId:int,cantidad:numeric-string,liberada:numeric-string,merma:numeric-string,envios:list<array{id:int,antes:array<string,mixed>,despues:array{cantidad_anulada:numeric-string,cantidad_iniciada:numeric-string,cantidad_lista:numeric-string}}>}>} $plan */
    private function aplicar(User $actor, int $pedidoId, int $detalleId, string $cantidad, string $motivo, string $clave, array $plan): AnulacionPedido
    {
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
        $maximo = $this->pendiente($raiz);
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
            $pendiente = $this->pendiente($hoja);
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

    /** @return array{huella:string,detalles:list<array{id:int,cantidad:string}>,mermas:int} */
    public function previsualizarPedido(User $actor, int $pedidoId, string $motivo): array
    {
        return $this->ejecutar($actor, 'pedidos.anular_enviados', fn () => $this->planPedido($actor, $pedidoId, $motivo));
    }

    public function anularPedido(User $actor, int $pedidoId, string $motivo, string $huella, int|string $motivoId = '', #[\SensitiveParameter] string $claveAutorizacion = ''): void
    {
        $this->autorizar($actor, $pedidoId, $motivoId, $motivo, $claveAutorizacion);
        $this->ejecutar($actor, 'pedidos.anular_enviados', function () use ($actor, $pedidoId, $motivo, $huella, $motivoId): void {
            $this->motivoVigente($motivoId, $motivo);
            $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);
            AccesoPos::pedido($actor, $pedido);
            if ($pedido->mesa_id) {
                Gate::forUser(AccesoPos::usuario($actor))->authorize('mesas.liberar');
            }
            // Repetir la confirmación no repite movimientos ni libera otra atención.
            if ($pedido->estado->value === 'anulado') {
                return;
            }
            $plan = $this->planPedido($actor, $pedidoId, $motivo);
            if (! hash_equals($plan['huella'], $huella)) {
                throw ValidationException::withMessages(['seguridad' => 'El pedido o Cocina cambió. Revisa nuevamente la anulación completa.']);
            }
            foreach ($plan['detalles'] as $fila) {
                // Releer dentro de la misma transacción: varias líneas pueden compartir insumos.
                $actual = $this->calcular($actor, $pedidoId, $fila['id'], $fila['cantidad'], $motivo);
                $this->aplicar($actor, $pedidoId, $fila['id'], $fila['cantidad'], $motivo, (string) Str::uuid(), $actual);
            }
            foreach ($pedido->detalles()->where('estado', 'borrador')->lockForUpdate()->get() as $detalle) {
                $detalle->update(['estado' => 'anulado', 'cantidad_anulada' => $detalle->cantidad]);
            }
            $ids = $pedido->detalles()->pluck('id');
            if (ReservaInventario::whereIn('pedido_detalle_id', $ids)->whereRaw('cantidad_consumida + cantidad_liberada <> cantidad')->exists()
                || EnvioCocinaDetalle::whereIn('pedido_detalle_id', $ids)->whereRaw('cantidad_entregada + cantidad_anulada < cantidad')->exists()) {
                throw ValidationException::withMessages(['seguridad' => 'Quedan reservas o cantidades pendientes en Cocina. No se anuló el pedido.']);
            }
            $pedido->update(['estado' => 'anulado', 'motivo_anulacion' => trim($motivo), 'anulado_por' => $actor->id, 'cerrado_at' => now()]);
            $pedido->delivery?->update(['estado' => 'cancelado']);
            OcupacionMesa::where('pedido_id', $pedidoId)->delete();
            activity()->causedBy($actor)->performedOn($pedido)->event('pedido_anulado')->withProperties(['motivo' => trim($motivo), 'lineas_confirmadas' => count($plan['detalles'])])->log('Pedido completo anulado y mesa liberada');
        });
    }

    /** @return array{huella:string,detalles:list<array{id:int,cantidad:string}>,mermas:int} */
    private function planPedido(User $actor, int $pedidoId, string $motivo): array
    {
        Validator::make(['motivo' => trim($motivo)], ['motivo' => ['required', 'string', 'min:5', 'max:500']])->validate();
        $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);
        AccesoPos::pedido($actor, $pedido);
        if ($pedido->mesa_id) {
            Gate::forUser(AccesoPos::usuario($actor))->authorize('mesas.liberar');
        }
        if (in_array($pedido->estado->value, ['cerrado', 'anulado'], true)) {
            throw ValidationException::withMessages(['seguridad' => 'La atención ya terminó.']);
        }
        $detalles = $pedido->detalles()->orderBy('id')->lockForUpdate()->get();
        if ($pedido->cobros()->exists() || $detalles->contains(fn ($d) => bccomp($d->cantidad_cobrada, '0', 4) > 0 || bccomp($d->cantidad_entregada, '0', 4) > 0 || bccomp($d->cantidad_cancelada_pagada, '0', 4) > 0)) {
            throw ValidationException::withMessages(['seguridad' => 'Este pedido tiene cobros o productos entregados. Resuelve sus cobros y devoluciones desde las opciones correspondientes; no se puede anular todo desde aquí.']);
        }
        $borradores = $detalles->where('estado.value', 'borrador');
        if ($borradores->contains(fn ($d) => $d->confirmacion_pedido_id !== null)
            || ReservaInventario::whereIn('pedido_detalle_id', $borradores->pluck('id'))->exists()
            || EnvioCocinaDetalle::whereIn('pedido_detalle_id', $borradores->pluck('id'))->exists()) {
            throw ValidationException::withMessages(['seguridad' => 'Un borrador tiene operaciones confirmadas. Revisa el pedido.']);
        }
        $filas = [];
        $huellas = [];
        $mermas = 0;
        foreach ($detalles->whereNull('detalle_padre_id')->where('estado.value', 'confirmado') as $detalle) {
            $cantidad = bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4);
            if (bccomp($cantidad, '0', 4) <= 0) {
                continue;
            }
            $cantidad = bcdiv($cantidad, '1', 0);
            $plan = $this->calcular($actor, $pedidoId, $detalle->id, $cantidad, $motivo);
            $filas[] = ['id' => $detalle->id, 'cantidad' => $cantidad];
            $huellas[] = $plan['huella'];
            foreach ($plan['hojas'] as $hoja) {
                if (bccomp($hoja['merma'], '0', 4) > 0) {
                    $mermas++;
                }
            }
        }

        return ['huella' => hash('sha256', json_encode([EstadoCuenta::huella($pedido), $huellas, trim($motivo)], JSON_THROW_ON_ERROR)), 'detalles' => $filas, 'mermas' => $mermas];
    }
}
