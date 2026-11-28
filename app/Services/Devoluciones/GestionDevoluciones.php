<?php

namespace App\Services\Devoluciones;

use App\Models\Cobro;
use App\Models\Devolucion;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Cajas\AccesoCajas;
use App\Services\Cobros\CalculoBeneficios;
use App\Services\Compras\ImportesCompra;
use App\Services\Inventario\CalculoProduccion;
use App\Services\Pos\TransaccionPos;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type Seleccion array<string,array{detalleId:string,cantidad:string}>
 * @phpstan-type Pagos array<string,array{pagoId:string,monto:string,referencia:string}>
 * @phpstan-type Plan array{huella:string,total:numeric-string,efectivo:numeric-string,envio:numeric-string,caja:string,cajero:string,motivo:string,lineas:list<array{detalleId:int,descripcion:string,cantidad:numeric-string,importe:numeric-string}>,pagos:list<array{pagoId:int,medioId:int,nombre:string,efectivo:bool,monto:numeric-string,referencia:string}>}
 */
class GestionDevoluciones
{
    use TransaccionPos;

    /**
     * @param  Seleccion  $seleccion
     * @param  Pagos  $pagos
     * @return Plan
     */
    public function previsualizar(User $actor, int $cobroId, int $turnoId, array $seleccion, array $pagos, bool $envio, string $motivo): array
    {
        return $this->ejecutar($actor, 'devoluciones.crear', fn () => $this->calcular($actor, $cobroId, $turnoId, $seleccion, $pagos, $envio, $motivo));
    }

    /**
     * @param  Seleccion  $seleccion
     * @param  Pagos  $pagos
     */
    public function devolver(User $actor, int $cobroId, int $turnoId, array $seleccion, array $pagos, bool $envio, string $motivo, string $clave, string $huella): Devolucion
    {
        return $this->ejecutar($actor, 'devoluciones.crear', function () use ($actor, $cobroId, $turnoId, $seleccion, $pagos, $envio, $motivo, $clave, $huella): Devolucion {
            AccesoDevoluciones::cobro($actor, Cobro::findOrFail($cobroId));
            Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
            if ($existente = Devolucion::where('clave_idempotencia', $clave)->lockForUpdate()->first()) {
                abort_unless($existente->cobro_id === $cobroId && $existente->turno_caja_id === $turnoId && $existente->usuario_id === $actor->id, 403);
                AccesoDevoluciones::devolucion($actor, $existente);

                return $existente;
            }
            $plan = $this->calcular($actor, $cobroId, $turnoId, $seleccion, $pagos, $envio, $motivo);
            if (! hash_equals($plan['huella'], $huella)) {
                throw ValidationException::withMessages(['seguridad' => 'Cambió el cobro, sus devoluciones o la caja. Vuelve a revisar.']);
            }
            $registro = Devolucion::create(['codigo' => 'DEV-'.Str::ulid(), 'cobro_id' => $cobroId, 'turno_caja_id' => $turnoId, 'usuario_id' => $actor->id, 'autorizado_por' => $actor->id,
                'usuario_nombre' => $plan['cajero'], 'caja_nombre' => $plan['caja'], 'estado' => 'confirmada', 'total' => $plan['total'], 'cargo_envio' => $plan['envio'], 'motivo' => $plan['motivo'], 'devuelta_at' => now(), 'clave_idempotencia' => $clave]);
            foreach ($plan['lineas'] as $linea) {
                $registro->detalles()->create(['cobro_detalle_id' => $linea['detalleId'], 'cantidad' => $linea['cantidad'], 'importe' => $linea['importe'], 'destino_producto' => 'sin_reingreso']);
            }
            foreach ($plan['pagos'] as $indice => $fila) {
                $pago = $registro->pagos()->create(['pago_original_id' => $fila['pagoId'], 'medio_pago_id' => $fila['medioId'], 'medio_pago_nombre' => $fila['nombre'], 'afecta_efectivo' => $fila['efectivo'], 'monto' => $fila['monto'], 'referencia' => $fila['referencia'] ?: null, 'clave_idempotencia' => 'devolucion:'.$registro->id.':'.$indice]);
                if ($fila['efectivo']) {
                    MovimientoCaja::create(['turno_caja_id' => $turnoId, 'usuario_id' => $actor->id, 'tipo' => 'egreso', 'concepto' => 'Devolución '.$registro->codigo, 'monto' => $fila['monto'], 'motivo' => $plan['motivo'], 'origen_type' => $pago::class, 'origen_id' => $pago->id, 'registrado_at' => $registro->devuelta_at, 'clave_idempotencia' => 'devolucion-pago:'.$pago->id]);
                }
            }
            activity()->causedBy($actor)->performedOn($registro)->event('devolucion_confirmada')->withProperties(['cobro_id' => $cobroId, 'turno_id' => $turnoId, 'total' => $plan['total'], 'efectivo' => $plan['efectivo']])->log('Devolución registrada sin modificación automática de inventario ni comprobantes');

            return $registro;
        });
    }

    /**
     * @param  Seleccion  $seleccion
     * @param  Pagos  $pagos
     * @return Plan
     */
    private function calcular(User $actor, int $cobroId, int $turnoId, array $seleccion, array $pagos, bool $envio, string $motivo): array
    {
        $actual = AccesoDevoluciones::usuario($actor);
        $turno = $this->turno($turnoId);
        AccesoCajas::turno($actual, $turno);
        MedioPago::orderBy('id')->lockForUpdate()->get();
        $lectura = Cobro::findOrFail($cobroId);
        Pedido::lockForUpdate()->findOrFail($lectura->pedido_id);
        $cobro = Cobro::lockForUpdate()->findOrFail($cobroId);
        AccesoDevoluciones::cobro($actual, $cobro);
        if ($cobro->estado->value !== 'confirmado') {
            throw ValidationException::withMessages(['seguridad' => 'Solo se devuelven cobros confirmados.']);
        }
        $motivo = trim($motivo);
        Validator::make(compact('seleccion', 'pagos', 'motivo'), [
            'motivo' => ['required', 'string', 'min:5', 'max:500'], 'seleccion' => ['array', 'max:500'], 'seleccion.*' => ['array:detalleId,cantidad'],
            'seleccion.*.detalleId' => ['required', 'integer', 'distinct'], 'seleccion.*.cantidad' => ['required', 'string', 'regex:/^[1-9][0-9]{0,3}$/'],
            'pagos' => ['array', 'max:10'], 'pagos.*' => ['array:pagoId,monto,referencia'], 'pagos.*.pagoId' => ['required', 'integer', 'distinct'], 'pagos.*.monto' => ['present', 'string', 'max:20'], 'pagos.*.referencia' => ['present', 'nullable', 'string', 'max:150'],
        ])->validate();
        $saldo = SaldoDevolucion::calcular($cobro, true);
        $cargo = $envio ? $saldo['envio'] : '0.00';
        $total = $cargo;
        $lineas = [];
        foreach ($seleccion as $fila) {
            $linea = $saldo['lineas'][(int) $fila['detalleId']] ?? null;
            $cantidad = CalculoProduccion::cantidad($fila['cantidad']);
            if (! $linea || bccomp($cantidad, $linea['disponible'], 4) > 0) {
                throw ValidationException::withMessages(['seleccion' => 'La cantidad supera lo cobrado que aún no fue devuelto.']);
            }
            $importe = CalculoBeneficios::repartir($linea['importe'], $cantidad, $linea['disponible']);
            $total = ImportesCompra::redondear(bcadd($total, $importe, 2));
            $lineas[] = ['detalleId' => $linea['id'], 'descripcion' => $linea['descripcion'], 'cantidad' => $cantidad, 'importe' => $importe];
        }
        if ($lineas === [] && bccomp($cargo, '0', 2) <= 0) {
            throw ValidationException::withMessages(['seleccion' => 'Selecciona productos o un cargo de envío pendiente de devolución.']);
        }
        $aplicado = $efectivo = '0.00';
        $planPagos = [];
        foreach ($pagos as $fila) {
            if ($fila['monto'] === '' || $fila['monto'] === '0' || $fila['monto'] === '0.00') {
                continue;
            }
            $original = $saldo['pagos'][(int) $fila['pagoId']] ?? null;
            $monto = ImportesCompra::importe($fila['monto'], 'pagos', 2, true);
            if (! $original || bccomp($monto, $original['disponible'], 2) > 0) {
                throw ValidationException::withMessages(['pagos' => 'No puedes devolver más de lo aplicado en cada pago original. El vuelto está excluido.']);
            }
            $referencia = trim($fila['referencia']);
            if ($original['requiereReferencia'] && $referencia === '') {
                throw ValidationException::withMessages(['pagos' => 'Registra la referencia de la devolución por '.$original['nombre'].'.']);
            }
            $aplicado = bcadd($aplicado, $monto, 2);
            $efectivo = bcadd($efectivo, $original['efectivo'] ? $monto : '0', 2);
            $planPagos[] = ['pagoId' => $original['id'], 'medioId' => $original['medioId'], 'nombre' => $original['nombre'], 'efectivo' => $original['efectivo'], 'monto' => $monto, 'referencia' => $referencia];
        }
        if (bccomp($aplicado, $total, 2) !== 0 || bccomp($total, $saldo['disponible'], 2) > 0) {
            throw ValidationException::withMessages(['pagos' => 'Los reintegros deben sumar exactamente S/ '.$total.' y respetar lo pendiente de devolver.']);
        }
        $esperado = $turno->efectivoEsperado(bloquear: true);
        if (bccomp($efectivo, '0', 2) > 0) {
            Gate::forUser($actual)->authorize('cajas.registrar_egreso');
            if (bccomp($esperado, $efectivo, 2) < 0) {
                throw ValidationException::withMessages(['turnoId' => 'La caja actual no tiene efectivo suficiente para esta devolución.']);
            }
        }
        $plan = ['total' => $total, 'efectivo' => $efectivo, 'envio' => $cargo, 'caja' => $turno->caja_nombre ?? $turno->caja->nombre, 'cajero' => $actual->name, 'motivo' => trim($motivo), 'lineas' => $lineas, 'pagos' => $planPagos];
        $plan['huella'] = hash('sha256', json_encode([$saldo['huella'], $turno->getAttributes(), $esperado, $plan], JSON_THROW_ON_ERROR));

        return $plan;
    }
}
