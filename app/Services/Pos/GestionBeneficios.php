<?php

namespace App\Services\Pos;

use App\Models\BeneficioPedido;
use App\Models\CobroDetalle;
use App\Models\MotivoBeneficio;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\User;
use App\Services\Cobros\CalculoBeneficios;
use App\Services\Compras\ImportesCompra;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionBeneficios
{
    use TransaccionPos;

    /** @param array{accion:string,tipo:string,valor:string,motivoId:string,motivo:string} $datos
     * @return array<string,mixed>
     */
    public function previsualizar(User $actor, int $pedidoId, int $detalleId, array $datos): array
    {
        return $this->ejecutar($actor, 'pos.ver', fn () => $this->calcular($actor, $pedidoId, $detalleId, $datos));
    }

    /** @param array{accion:string,tipo:string,valor:string,motivoId:string,motivo:string} $datos */
    public function aplicar(User $actor, int $pedidoId, int $detalleId, string $clave, string $huella, array $datos): BeneficioPedido
    {
        return $this->ejecutar($actor, 'pos.ver', function () use ($actor, $pedidoId, $detalleId, $clave, $huella, $datos): BeneficioPedido {
            AccesoPos::pedido($actor, Pedido::findOrFail($pedidoId));
            Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
            if ($existente = BeneficioPedido::where('clave_idempotencia', $clave)->lockForUpdate()->first()) {
                abort_unless($existente->pedido_id === $pedidoId && $existente->pedido_detalle_id === $detalleId && $existente->usuario_id === $actor->id, 403);
                $permiso = $existente->accion === 'cortesia' || ($existente->accion === 'quitar' && ($existente->antes['es_cortesia'] ?? false)) ? 'cortesias.aplicar' : 'descuentos.aplicar';
                Gate::forUser(User::findOrFail($actor->id))->authorize($permiso);

                return $existente;
            }
            $plan = $this->calcular($actor, $pedidoId, $detalleId, $datos);
            if (! hash_equals((string) $plan['huella'], $huella)) {
                throw ValidationException::withMessages(['seguridad' => 'El pedido o el motivo cambiaron. Revisa el beneficio nuevamente.']);
            }
            $detalle = PedidoDetalle::findOrFail($detalleId);
            $antes = $detalle->only(array_keys($plan['actualizacion']));
            $detalle->update($plan['actualizacion']);
            $registro = BeneficioPedido::create(['clave_idempotencia' => $clave, 'pedido_id' => $pedidoId, 'pedido_detalle_id' => $detalleId, 'usuario_id' => $actor->id, 'usuario_nombre' => User::findOrFail($actor->id)->name,
                'accion' => $datos['accion'], 'motivo' => trim($datos['motivo']), 'antes' => $antes, 'despues' => $detalle->only(array_keys($plan['actualizacion'])), 'autorizado_at' => now()]);
            activity()->causedBy($actor)->performedOn($detalle)->event('beneficio_'.$datos['accion'])->withProperties(['registro_id' => $registro->id, 'antes' => $antes, 'despues' => $detalle->only(array_keys($plan['actualizacion']))])->log('Beneficio de producto autorizado');

            return $registro;
        });
    }

    /** @param array{accion:string,tipo:string,valor:string,motivoId:string,motivo:string} $datos
     * @return array<string,mixed>
     */
    private function calcular(User $actor, int $pedidoId, int $detalleId, array $datos): array
    {
        $actual = AccesoPos::usuario($actor);
        Validator::make($datos, ['accion' => ['required', Rule::in(['descuento', 'cortesia', 'quitar'])], 'tipo' => ['present', 'string'], 'valor' => ['present', 'string', 'max:20'],
            'motivoId' => ['present', 'nullable', 'integer'], 'motivo' => ['required', 'string', 'min:5', 'max:500']])->validate();
        $motivos = MotivoBeneficio::orderBy('id')->lockForUpdate()->get();
        $pedido = Pedido::lockForUpdate()->findOrFail($pedidoId);
        AccesoPos::pedido($actual, $pedido);
        $detalle = $pedido->detalles()->whereNull('detalle_padre_id')->lockForUpdate()->findOrFail($detalleId);
        if (in_array($pedido->estado->value, ['cerrado', 'anulado'], true) || $detalle->estado->value !== 'confirmado' || bccomp(bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4), '0', 4) <= 0) {
            throw ValidationException::withMessages(['seguridad' => 'Selecciona una línea confirmada y vigente.']);
        }
        if (bccomp($detalle->cantidad_cobrada, '0', 4) !== 0 || CobroDetalle::where('pedido_detalle_id', $detalleId)->exists()) {
            throw ValidationException::withMessages(['seguridad' => 'El producto ya tiene cobros. No se modifica el beneficio de cantidades liquidadas.']);
        }
        $tenia = $detalle->es_cortesia || $detalle->descuento_tipo !== null || bccomp($detalle->descuento_importe, '0', 2) !== 0;
        if ($tenia) {
            Gate::forUser($actual)->authorize($detalle->es_cortesia ? 'cortesias.aplicar' : 'descuentos.aplicar');
            if (bccomp($detalle->descuento_importe, '0', 2) > 0 && bccomp($detalle->descuento_importe, bcmul(bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4), $detalle->precio_unitario, 2), 2) === 0) {
                Gate::forUser($actual)->authorize('cortesias.aplicar');
            }
        }
        if ($datos['accion'] === 'quitar' && ! $tenia) {
            throw ValidationException::withMessages(['seguridad' => 'Este producto no tiene un beneficio que retirar.']);
        }
        if ($datos['accion'] !== 'quitar') {
            Gate::forUser($actual)->authorize($datos['accion'] === 'cortesia' ? 'cortesias.aplicar' : 'descuentos.aplicar');
        }
        $motivo = $datos['accion'] !== 'quitar' ? $motivos->firstWhere('id', (int) $datos['motivoId']) : null;
        if ($datos['accion'] !== 'quitar' && (! $motivo || ! $motivo->activo || ! in_array($motivo->tipo->value, ['ambos', $datos['accion']], true))) {
            throw ValidationException::withMessages(['beneficioMotivoId' => 'Selecciona un motivo activo compatible con el beneficio.']);
        }
        $cantidad = bcsub($detalle->cantidad, $detalle->cantidad_anulada, 4);
        $base = ImportesCompra::redondear(bcmul($cantidad, $detalle->precio_unitario, 6));
        $importe = $datos['accion'] === 'cortesia' ? $base : ($datos['accion'] === 'quitar' ? '0.00' : CalculoBeneficios::presupuesto($base, $datos['tipo'], $datos['valor']));
        if ($datos['accion'] === 'descuento' && bccomp($importe, $base, 2) === 0) {
            Gate::forUser($actual)->authorize('cortesias.aplicar');
        }
        $cambios = ['es_cortesia' => $datos['accion'] === 'cortesia', 'descuento_tipo' => $datos['accion'] === 'descuento' ? $datos['tipo'] : null,
            'descuento_valor' => $datos['accion'] === 'descuento' ? ImportesCompra::importe($datos['valor'], 'beneficioValor') : '0', 'descuento_importe' => $importe,
            'motivo_beneficio_id' => $motivo?->id, 'autorizado_por' => $datos['accion'] === 'quitar' ? null : $actual->id,
            'beneficio_motivo_nombre' => $motivo?->nombre, 'beneficio_motivo_texto' => $datos['accion'] === 'quitar' ? null : trim($datos['motivo']),
            'beneficio_autorizado_nombre' => $datos['accion'] === 'quitar' ? null : $actual->name, 'beneficio_autorizado_at' => $datos['accion'] === 'quitar' ? null : now()->toDateTimeString()];
        $huella = hash('sha256', json_encode([GestionPedidos::huella($pedido), $motivo?->getAttributes(), $datos], JSON_THROW_ON_ERROR));

        return ['huella' => $huella, 'descripcion' => $detalle->descripcion, 'cantidad' => $cantidad, 'importe' => $importe, 'actualizacion' => $cambios];
    }
}
