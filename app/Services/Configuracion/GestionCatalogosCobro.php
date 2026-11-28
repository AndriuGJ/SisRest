<?php

namespace App\Services\Configuracion;

use App\Models\MedioPago;
use App\Models\TipoComprobante;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionCatalogosCobro
{
    public function guardarMedio(
        User $actor,
        ?int $id,
        array $datos
    ): MedioPago {
        return $this->transaccion($actor, function (User $actual) use ($id, $datos) {
            $todos = MedioPago::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $medio = $id
                ? $todos->firstWhere('id', $id)
                : new MedioPago;

            abort_unless($medio instanceof MedioPago, 404);

            // El código y el tipo de efectivo son inmutables
            // para no alterar su interpretación contable.
            $codigo = $medio->exists
                ? $medio->codigo
                : Str::lower(trim($datos['codigo']));

            $afectaEfectivo = $medio->exists
                ? $medio->afecta_efectivo
                : $datos['afecta_efectivo'];

            $referencia = in_array($codigo, ['yape', 'plin'], true)
                ? true
                : $datos['requiere_referencia'];

            $validado = Validator::make([
                'codigo' => $codigo,
                'nombre' => Str::squish($datos['nombre']),
                'afecta_efectivo' => $afectaEfectivo,
                'requiere_referencia' => $referencia,
                'activo' => $datos['activo'],
            ], [
                'codigo' => [
                    'required', 'string', 'max:30',
                    'regex:/^[a-z0-9_-]+$/',
                    Rule::unique('medios_pago', 'codigo')->ignore($id),
                ],
                'nombre' => ['required', 'string', 'max:80'],
                'afecta_efectivo' => ['required', 'boolean'],
                'requiere_referencia' => ['required', 'boolean'],
                'activo' => ['required', 'boolean'],
            ])->validate();

            $this->verificarEfectivo(
                $todos,
                $medio,
                (bool) $validado['activo']
            );

            $antes = $medio->exists
                ? $medio->only($medio->getFillable())
                : [];

            $medio->fill($validado)->save();

            activity()
                ->causedBy($actual)
                ->performedOn($medio)
                ->event('configuracion_restaurante')
                ->withProperties([
                    'antes' => $antes,
                    'despues' => $medio->only($medio->getFillable()),
                ])
                ->log($id ? 'Medio de pago actualizado' : 'Medio de pago registrado');

            return $medio;
        });
    }

    public function cambiarEstadoMedio(
        User $actor,
        int $id,
        bool $activo
    ): void {
        $this->transaccion($actor, function (User $actual) use ($id, $activo) {
            $todos = MedioPago::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $medio = $todos->firstWhere('id', $id);
            abort_unless($medio instanceof MedioPago, 404);

            $this->verificarEfectivo($todos, $medio, $activo);

            $medio->update(['activo' => $activo]);

            activity()
                ->causedBy($actual)
                ->performedOn($medio)
                ->event('configuracion_restaurante')
                ->log($activo ? 'Medio de pago activado' : 'Medio de pago desactivado');
        });
    }

    public function cambiarEstadoDocumento(
        User $actor,
        int $id,
        bool $activo
    ): void {
        $this->transaccion($actor, function (User $actual) use ($id, $activo) {
            $tipo = TipoComprobante::query()
                ->whereIn('codigo', ['01', '03'])
                ->lockForUpdate()
                ->findOrFail($id);

            $tipo->update(['disponible_venta' => $activo]);

            activity()
                ->causedBy($actual)
                ->performedOn($tipo)
                ->event('configuracion_restaurante')
                ->log(
                    ($activo ? 'Documento habilitado: ' : 'Documento deshabilitado: ')
                    .$tipo->nombre
                );
        });
    }

    private function verificarEfectivo(
        $todos,
        MedioPago $medio,
        bool $activo
    ): void {
        if ($activo || ! $medio->afecta_efectivo) {
            return;
        }

        $existeOtro = $todos->contains(
            fn (MedioPago $otro) =>
                $otro->id !== $medio->id
                && $otro->activo
                && $otro->afecta_efectivo
        );

        if (! $existeOtro) {
            throw ValidationException::withMessages([
                'seguridad' => 'Debe permanecer activo al menos un medio de pago en efectivo.',
            ]);
        }
    }

    private function transaccion(
        User $actor,
        Closure $operacion
    ): mixed {
        Gate::forUser($actor)->authorize('configuracion.restaurante');

        return DB::transaction(function () use ($actor, $operacion) {
            $actual = User::findOrFail($actor->id);

            abort_unless($actual->activo, 403);

            Gate::forUser($actual)->authorize(
                'configuracion.restaurante'
            );

            return $operacion($actual);
        }, 3);
    }
}
