<?php

namespace App\Services\Cajas;

use App\Models\ArqueoCaja;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Compras\ImportesCompra;
use App\Services\Inventario\TransaccionCatalogo;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionCajas
{
    use TransaccionCatalogo;

    /**
     * @template T
     *
     * @param  Closure():T  $operacion
     * @return T
     */
    private function ejecutar(User $actor, string $permiso, Closure $operacion): mixed
    {
        return $this->transaccion($actor, $permiso, function () use ($actor, $operacion) {
            AccesoCajas::usuario($actor);
            Caja::orderBy('id')->lockForUpdate()->get();

            return $operacion();
        });
    }

    public function abrir(User $actor, int $cajaId, int $responsableId, string $fondo, string $observaciones, string $clave): TurnoCaja
    {
        return $this->ejecutar($actor, 'cajas.abrir', function () use ($actor, $cajaId, $responsableId, $fondo, $observaciones, $clave): TurnoCaja {
            $this->validarClave($clave);
            if ($existente = TurnoCaja::where('clave_apertura', $clave)->lockForUpdate()->first()) {
                abort_unless($existente->abierta_por === $actor->id && $existente->caja_id === $cajaId && $existente->usuario_id === $responsableId, 403);

                return $existente;
            }
            $actual = AccesoCajas::usuario($actor);
            abort_unless($responsableId === $actor->id || $actual->can('cajas.ver_todas'), 403);
            $responsable = AccesoCajas::usuario(User::findOrFail($responsableId));
            $caja = Caja::findOrFail($cajaId);
            if (! $caja->activo || TurnoCaja::where('caja_id', $cajaId)->where('estado', 'abierta')->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['cajaId' => 'Esta caja está inactiva o ya tiene un turno abierto.']);
            }
            $importe = ImportesCompra::importe($fondo, 'fondo');
            Validator::make(['observaciones' => $observaciones], ['observaciones' => ['nullable', 'string', 'max:2000']])->validate();
            $turno = TurnoCaja::create(['caja_id' => $caja->id, 'caja_nombre' => $caja->nombre, 'usuario_id' => $responsable->id,
                'responsable_nombre' => $responsable->name, 'abierta_por' => $actor->id, 'clave_apertura' => $clave,
                'estado' => 'abierta', 'fondo_inicial' => $importe, 'abierta_at' => now(), 'observaciones' => trim($observaciones) ?: null]);
            activity()->causedBy($actor)->performedOn($turno)->event('caja_abierta')->withProperties(['responsable_id' => $responsable->id, 'fondo_inicial' => $importe])->log('Turno de caja abierto');

            return $turno;
        });
    }

    /** @param array{tipo:string,monto:string,concepto:string,motivo:string} $datos */
    public function movimiento(User $actor, int $turnoId, string $clave, string $version, array $datos): MovimientoCaja
    {
        Validator::make($datos, ['tipo' => ['required', Rule::in(['ingreso', 'egreso', 'retiro'])]])->validate();
        $permiso = ['ingreso' => 'cajas.registrar_ingreso', 'egreso' => 'cajas.registrar_egreso', 'retiro' => 'cajas.retirar'][$datos['tipo']];

        return $this->ejecutar($actor, $permiso, function () use ($actor, $turnoId, $clave, $version, $datos): MovimientoCaja {
            $this->validarClave($clave);
            $turno = TurnoCaja::lockForUpdate()->findOrFail($turnoId);
            AccesoCajas::turno($actor, $turno);
            if ($existente = MovimientoCaja::where('clave_idempotencia', 'manual:'.$clave)->lockForUpdate()->first()) {
                abort_unless($existente->turno_caja_id === $turnoId && $existente->usuario_id === $actor->id && $existente->tipo->value === $datos['tipo'], 403);

                return $existente;
            }
            $resumen = $this->abierto($turno, $version);
            if (! $turno->caja->activo) {
                throw ValidationException::withMessages(['seguridad' => 'La caja está inactiva. Solo se permite arquear o cerrar el turno.']);
            }
            $datos['concepto'] = Str::squish($datos['concepto']);
            $datos['motivo'] = trim($datos['motivo']);
            Validator::make($datos, ['concepto' => ['required', 'string', 'min:3', 'max:100'], 'motivo' => ['required', 'string', 'min:5', 'max:500']])->validate();
            $monto = ImportesCompra::importe($datos['monto'], 'monto', 2, true);
            $nuevo = $datos['tipo'] === 'ingreso' ? bcadd($resumen['esperado'], $monto, 2) : bcsub($resumen['esperado'], $monto, 2);
            if (bccomp($nuevo, '0', 2) < 0 || bccomp($nuevo, '999999999999.99', 2) > 0) {
                throw ValidationException::withMessages(['monto' => 'El movimiento supera el efectivo disponible o el rango permitido.']);
            }
            $movimiento = MovimientoCaja::create(['turno_caja_id' => $turnoId, 'usuario_id' => $actor->id, 'tipo' => $datos['tipo'],
                'concepto' => trim($datos['concepto']), 'monto' => $monto, 'motivo' => trim($datos['motivo']),
                'clave_idempotencia' => 'manual:'.$clave, 'registrado_at' => now()]);
            activity()->causedBy($actor)->performedOn($movimiento)->event('caja_movimiento')->withProperties(['turno_id' => $turnoId, 'tipo' => $datos['tipo'], 'monto' => $monto])->log('Movimiento manual de efectivo registrado');

            return $movimiento;
        });
    }

    public function arquear(User $actor, int $turnoId, string $tipo, string $contado, string $observaciones, string $clave, string $version): ArqueoCaja
    {
        Validator::make(['tipo' => $tipo], ['tipo' => ['required', Rule::in(['parcial', 'cierre'])]])->validate();

        return $this->ejecutar($actor, $tipo === 'cierre' ? 'cajas.cerrar' : 'cajas.arquear', function () use ($actor, $turnoId, $tipo, $contado, $observaciones, $clave, $version): ArqueoCaja {
            $this->validarClave($clave);
            $turno = TurnoCaja::lockForUpdate()->findOrFail($turnoId);
            AccesoCajas::turno($actor, $turno);
            if ($existente = ArqueoCaja::where('clave_idempotencia', $clave)->lockForUpdate()->first()) {
                abort_unless($existente->turno_caja_id === $turnoId && $existente->usuario_id === $actor->id && $existente->tipo->value === $tipo, 403);

                return $existente;
            }
            $resumen = $this->abierto($turno, $version);
            $importe = ImportesCompra::importe($contado, 'contado');
            $diferencia = bcsub($importe, $resumen['esperado'], 2);
            foreach ([$resumen['esperado'], $diferencia] as $valor) {
                if (bccomp($valor, '-999999999999.99', 2) < 0 || bccomp($valor, '999999999999.99', 2) > 0) {
                    throw ValidationException::withMessages(['seguridad' => 'El saldo supera el rango admitido para el arqueo.']);
                }
            }
            Validator::make(['observaciones' => trim($observaciones)], ['observaciones' => [bccomp($diferencia, '0', 2) !== 0 ? 'required' : 'nullable', 'string', 'max:2000', 'min:5']],
                ['observaciones.required' => 'Explica la diferencia entre el efectivo esperado y el contado.'])->validate();
            $arqueo = ArqueoCaja::create(['turno_caja_id' => $turnoId, 'usuario_id' => $actor->id, 'tipo' => $tipo,
                'efectivo_esperado' => $resumen['esperado'], 'efectivo_contado' => $importe, 'diferencia' => $diferencia,
                'observaciones' => trim($observaciones) ?: null, 'contado_at' => now(), 'clave_idempotencia' => $clave, 'resumen' => $resumen]);
            if ($tipo === 'cierre') {
                $turno->update(['estado' => 'cerrada', 'cerrada_at' => now(), 'cerrada_por' => $actor->id]);
            }
            activity()->causedBy($actor)->performedOn($arqueo)->event($tipo === 'cierre' ? 'caja_cerrada' : 'caja_arqueada')
                ->withProperties(['turno_id' => $turnoId, 'esperado' => $resumen['esperado'], 'contado' => $importe, 'diferencia' => $diferencia])->log($tipo === 'cierre' ? 'Turno cerrado con arqueo final' : 'Arqueo parcial registrado');

            return $arqueo;
        });
    }

    /** @return array{fondo:numeric-string,ingresos:numeric-string,egresos:numeric-string,retiros:numeric-string,esperado:numeric-string,cobrado:numeric-string,medios:array<int,array{nombre:string,monto:numeric-string}>,huella:string} */
    private function abierto(TurnoCaja $turno, string $version): array
    {
        if ($turno->estado->value !== 'abierta') {
            throw ValidationException::withMessages(['seguridad' => 'El turno ya está cerrado. Sus movimientos y arqueo final se conservan.']);
        }
        $resumen = app(ResumenTurno::class)->calcular($turno, bloquear: true);
        if (! hash_equals($resumen['huella'], $version)) {
            throw ValidationException::withMessages(['seguridad' => 'El turno cambió mientras revisabas la operación. Actualiza y vuelve a revisarla.']);
        }

        return $resumen;
    }

    private function validarClave(string $clave): void
    {
        Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
    }
}
