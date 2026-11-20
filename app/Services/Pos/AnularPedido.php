<?php

namespace App\Services\Pos;

use App\Models\EnvioCocinaDetalle;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\ReservaInventario;
use App\Models\User;
use App\Services\Cobros\EstadoCuenta;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AnularPedido
{
    use TransaccionPos;

    /** @return array{huella:string,detalles:list<array{id:int,cantidad:string}>,mermas:int} */
    public function previsualizar(User $actor, int $pedidoId, string $motivo): array
    {
        return $this->ejecutar($actor, 'pedidos.anular_enviados', fn () => $this->plan($actor, $pedidoId, $motivo));
    }

    public function anular(User $actor, int $pedidoId, string $motivo, string $huella): void
    {
        $this->ejecutar($actor, 'pedidos.anular_enviados', function () use ($actor, $pedidoId, $motivo, $huella): void {
            $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);
            AccesoPos::pedido($actor, $pedido);
            if ($pedido->mesa_id) {
                Gate::forUser(AccesoPos::usuario($actor))->authorize('mesas.liberar');
            }
            // Repetir la confirmación no repite movimientos ni libera otra atención.
            if ($pedido->estado->value === 'anulado') {
                return;
            }
            $plan = $this->plan($actor, $pedidoId, $motivo);
            if (! hash_equals($plan['huella'], $huella)) {
                throw ValidationException::withMessages(['seguridad' => 'El pedido o Cocina cambió. Revisa nuevamente la anulación completa.']);
            }
            $gestion = app(GestionAnulaciones::class);
            foreach ($plan['detalles'] as $fila) {
                // Releer dentro de la misma transacción: varias líneas pueden compartir insumos.
                $actual = $gestion->previsualizar($actor, $pedidoId, $fila['id'], $fila['cantidad'], $motivo);
                $gestion->anular($actor, $pedidoId, $fila['id'], $fila['cantidad'], $motivo, (string) Str::uuid(), $actual['huella']);
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
    private function plan(User $actor, int $pedidoId, string $motivo): array
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
            $plan = app(GestionAnulaciones::class)->previsualizar($actor, $pedidoId, $detalle->id, $cantidad, $motivo);
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
