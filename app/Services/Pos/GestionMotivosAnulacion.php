<?php

namespace App\Services\Pos;

use App\Models\MotivoAnulacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionMotivosAnulacion
{
    public function guardar(
        User $actor,
        ?int $id,
        array $datos
    ): MotivoAnulacion {
        Gate::forUser($actor)->authorize('configuracion.restaurante');

        return DB::transaction(function () use ($actor, $id, $datos) {
            $actual = User::findOrFail($actor->id);
            abort_unless($actual->activo, 403);

            Gate::forUser($actual)->authorize('configuracion.restaurante');

            $motivo = $id
                ? MotivoAnulacion::lockForUpdate()->findOrFail($id)
                : new MotivoAnulacion;

            $codigo = $motivo->exists
                ? $motivo->codigo
                : Str::lower(trim($datos['codigo']));

            $validado = Validator::make([
                'codigo' => $codigo,
                'nombre' => Str::squish($datos['nombre']),
                'orden' => $datos['orden'],
                'activo' => $datos['activo'],
            ], [
                'codigo' => [
                    'required', 'string', 'max:40',
                    'regex:/^[a-z0-9_-]+$/',
                    Rule::unique('motivos_anulacion', 'codigo')->ignore($id),
                ],
                'nombre' => ['required', 'string', 'max:100'],
                'orden' => ['required', 'integer', 'min:0', 'max:65535'],
                'activo' => ['required', 'boolean'],
            ])->validate();

            $antes = $motivo->exists
                ? $motivo->only($motivo->getFillable())
                : [];

            $motivo->fill($validado)->save();

            activity()
                ->causedBy($actual)
                ->performedOn($motivo)
                ->event('configuracion_restaurante')
                ->withProperties([
                    'antes' => $antes,
                    'despues' => $motivo->only($motivo->getFillable()),
                ])
                ->log($id
                    ? 'Motivo de anulación actualizado'
                    : 'Motivo de anulación creado');

            return $motivo;
        }, 3);
    }

    public function cambiarEstado(
        User $actor,
        int $id,
        bool $activo
    ): void {
        Gate::forUser($actor)->authorize('configuracion.restaurante');

        DB::transaction(function () use ($actor, $id, $activo) {
            $actual = User::findOrFail($actor->id);
            abort_unless($actual->activo, 403);

            Gate::forUser($actual)->authorize('configuracion.restaurante');

            $motivos = MotivoAnulacion::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $motivo = $motivos->firstWhere('id', $id);
            abort_unless($motivo instanceof MotivoAnulacion, 404);

            if (! $activo) {
                $otroActivo = $motivos->contains(
                    fn (MotivoAnulacion $otro) =>
                        $otro->id !== $motivo->id && $otro->activo
                );

                if (! $otroActivo) {
                    throw ValidationException::withMessages([
                        'seguridad' => 'Debe permanecer al menos un motivo activo.',
                    ]);
                }
            }

            $motivo->update(['activo' => $activo]);

            activity()
                ->causedBy($actual)
                ->performedOn($motivo)
                ->event('configuracion_restaurante')
                ->log($activo
                    ? 'Motivo de anulación activado'
                    : 'Motivo de anulación desactivado');
        }, 3);
    }
}
