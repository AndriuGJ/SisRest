<?php

namespace App\Services\Pos;

use App\Models\AreaPreparacion;
use App\Models\Articulo;
use App\Models\CobroDetalle;
use App\Models\ConfirmacionPedido;
use App\Models\EnvioCocina;
use App\Models\EnvioCocinaDetalle;
use App\Models\Pedido;
use App\Models\ReservaInventario;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfirmarPedido
{
    use TransaccionPos;

    public function confirmar(User $actor, int $pedidoId, int $turnoId, string $version, string $clave): ConfirmacionPedido
    {
        return $this->ejecutar($actor, 'pedidos.enviar', function () use ($actor, $pedidoId, $turnoId, $version, $clave): ConfirmacionPedido {
            AccesoPos::usuario($actor);
            Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
            $repetida = ConfirmacionPedido::where('clave_idempotencia', $clave)->lockForUpdate()->first();
            if ($repetida) {
                abort_unless($repetida->pedido_id === $pedidoId && $repetida->usuario_id === $actor->id, 403);
                AccesoPos::pedido($actor, Pedido::findOrFail($pedidoId));

                return $repetida;
            }
            $this->turno($turnoId);
            $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);
            AccesoPos::pedido($actor, $pedido);
            if (in_array($pedido->estado->value, ['cerrado', 'anulado'], true) || ! hash_equals(GestionPedidos::huella($pedido), $version)) {
                throw ValidationException::withMessages(['seguridad' => 'El pedido cambió. Vuelve a abrirlo antes de confirmar.']);
            }
            $detalles = $pedido->detalles()->with(['producto.articulo.unidadMedida', 'producto.categoria', 'hijos'])->where('estado', 'borrador')->orderBy('id')->lockForUpdate()->get();
            if ($detalles->isEmpty()) {
                throw ValidationException::withMessages(['seguridad' => 'Guarda productos en borrador antes de confirmar.']);
            }
            $necesario = [];
            foreach ($detalles as $detalle) {
                if ($detalle->confirmacion_pedido_id || $detalle->confirmado_at || bccomp($detalle->cantidad, '0', 4) <= 0
                    || bccomp($detalle->cantidad_anulada, '0', 4) !== 0 || bccomp($detalle->cantidad_entregada, '0', 4) !== 0 || bccomp($detalle->cantidad_cobrada, '0', 4) !== 0
                    || $detalle->reservas()->exists() || EnvioCocinaDetalle::where('pedido_detalle_id', $detalle->id)->exists() || CobroDetalle::where('pedido_detalle_id', $detalle->id)->exists()) {
                    throw ValidationException::withMessages(['seguridad' => 'El borrador contiene una línea con operaciones previas.']);
                }
                $producto = $detalle->producto;
                if (! $producto->activo || (! $detalle->detalle_padre_id && (! $producto->visible_pos || ! $producto->categoria?->activo))) {
                    throw ValidationException::withMessages(['seguridad' => 'El producto '.$detalle->descripcion.' ya no está habilitado.']);
                }
                if ($producto->tipo->value === 'combo') {
                    if ($detalle->detalle_padre_id || $detalle->hijos->isEmpty()) {
                        throw ValidationException::withMessages(['seguridad' => 'Revisa los componentes guardados del combo.']);
                    }
                    foreach ($detalle->hijos as $hijo) {
                        if (! $detalles->contains('id', $hijo->id) || $hijo->tipo_linea->value !== 'componente' || ! $hijo->factor_componente
                            || bccomp($hijo->factor_componente, '0', 4) <= 0 || bccomp($hijo->precio_unitario, '0', 2) !== 0
                            || bccomp($hijo->cantidad, bcmul($detalle->cantidad, $hijo->factor_componente, 4), 4) !== 0) {
                            throw ValidationException::withMessages(['seguridad' => 'Los componentes no corresponden a la cantidad del combo.']);
                        }
                    }

                    continue;
                }
                if ($detalle->detalle_padre_id && (! $detalles->contains('id', $detalle->detalle_padre_id) || $detalle->padre?->producto->tipo->value !== 'combo')) {
                    throw ValidationException::withMessages(['seguridad' => 'El componente no tiene un combo válido en el borrador.']);
                }
                if (! $producto->articulo?->activo || ! $producto->articulo->unidadMedida->activo || $detalle->hijos->isNotEmpty()) {
                    throw ValidationException::withMessages(['seguridad' => 'Revisa el artículo y la unidad de '.$detalle->descripcion.'.']);
                }
                if ($detalle->area_preparacion_id && ! AreaPreparacion::findOrFail($detalle->area_preparacion_id)->activo) {
                    throw ValidationException::withMessages(['seguridad' => 'El destino guardado de '.$detalle->descripcion.' está inactivo.']);
                }
                $articuloId = $producto->articulo->id;
                $necesario[$articuloId] = bcadd($necesario[$articuloId] ?? '0', $detalle->cantidad, 4);
            }
            foreach ($necesario as $id => $cantidad) {
                $articulo = Articulo::findOrFail($id);
                if (bccomp($articulo->disponible, $cantidad, 4) < 0) {
                    throw ValidationException::withMessages(['seguridad' => 'No hay suficiente '.$articulo->nombre.'. No se confirmó ningún producto.']);
                }
            }
            $ultimoLote = (int) ConfirmacionPedido::where('pedido_id', $pedidoId)->lockForUpdate()->get()->max('numero_lote');
            $ultimoEnvio = (int) EnvioCocina::where('pedido_id', $pedidoId)->lockForUpdate()->get()->max('numero_envio');
            $lote = ConfirmacionPedido::create(['clave_idempotencia' => $clave, 'pedido_id' => $pedidoId, 'turno_caja_id' => $turnoId,
                'usuario_id' => $actor->id, 'numero_lote' => max($ultimoLote, $ultimoEnvio) + 1, 'confirmado_at' => now()]);
            $envios = [];
            foreach ($detalles as $detalle) {
                $detalle->update(['estado' => 'confirmado', 'confirmado_at' => $lote->confirmado_at, 'confirmacion_pedido_id' => $lote->id]);
                if ($detalle->producto->tipo->value === 'combo') {
                    continue;
                }
                ReservaInventario::create(['pedido_detalle_id' => $detalle->id, 'articulo_id' => $detalle->producto->articulo->id,
                    'cantidad' => $detalle->cantidad, 'clave_idempotencia' => 'pos:'.$lote->id.':'.$detalle->id]);
                if ($detalle->area_preparacion_id) {
                    $area = AreaPreparacion::findOrFail($detalle->area_preparacion_id);
                    $envio = $envios[$area->id] ??= EnvioCocina::create(['pedido_id' => $pedidoId, 'area_preparacion_id' => $area->id,
                        'area_nombre' => $area->nombre, 'usuario_id' => $actor->id, 'numero_envio' => $lote->numero_lote, 'confirmacion_pedido_id' => $lote->id, 'enviado_at' => $lote->confirmado_at]);
                    $envio->detalles()->create(['pedido_detalle_id' => $detalle->id, 'descripcion' => $detalle->descripcion, 'nota' => $detalle->nota, 'cantidad' => $detalle->cantidad]);
                }
            }
            foreach ($necesario as $id => $cantidad) {
                $articulo = Articulo::findOrFail($id);
                $articulo->update(['stock_reservado' => bcadd($articulo->stock_reservado, $cantidad, 4)]);
            }
            EstadoAtencion::actualizar($pedido);
            activity()->causedBy($actor)->performedOn($pedido)->event('pedido_confirmado')->withProperties(['lote_id' => $lote->id, 'turno_id' => $turnoId, 'envios' => count($envios)])->log('Productos confirmados y reservados por lote');

            return $lote;
        });
    }
}
