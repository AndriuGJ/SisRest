<?php

namespace App\Services\Configuracion;

use App\Enums\EstadoPedido;
use App\Models\Mesa;
use App\Models\Salon;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionRestaurante
{
    /** @param array{codigo: string, nombre: string, orden: string, activo: bool} $datos */
    public function guardarSalon(User $actor, ?int $id, array $datos): Salon
    {
        return $this->transaccion($actor, function () use ($actor, $id, $datos): Salon {
            $salon = $id ? Salon::query()->lockForUpdate()->findOrFail($id) : new Salon;
            $datos['codigo'] = Str::lower(trim($datos['codigo']));
            $datos['nombre'] = Str::squish($datos['nombre']);
            $validado = Validator::make($datos, [
                'codigo' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/', Rule::unique('salones', 'codigo')->ignore($id)],
                'nombre' => ['required', 'string', 'max:100'],
                'orden' => ['required', 'integer', 'min:0', 'max:65535'],
                'activo' => ['required', 'boolean'],
            ], ['codigo.regex' => 'Usa letras sin tildes, números, guion o guion bajo.'], [
                'codigo' => 'código', 'nombre' => 'nombre', 'orden' => 'orden',
            ])->validate();
            if ($id && ! $validado['activo']) {
                $this->comprobarSalonLibre($salon);
            }
            $antes = $salon->only($salon->getFillable());
            $salon->fill($validado)->save();
            $this->auditar($actor, $salon, $antes, $id ? 'Salón actualizado' : 'Salón registrado');

            return $salon;
        });
    }

    /** @param array{salonId: string, codigo: string, capacidad: string, activo: bool} $datos */
    public function guardarMesa(User $actor, ?int $id, array $datos): Mesa
    {
        return $this->transaccion($actor, function () use ($actor, $id, $datos): Mesa {
            $mesa = $id ? Mesa::query()->lockForUpdate()->findOrFail($id) : new Mesa;
            if ($id) {
                $this->comprobarMesaLibre($mesa);
            }
            $datos['codigo'] = Str::upper(Str::squish($datos['codigo']));
            $validado = Validator::make($datos, [
                'salonId' => ['required', 'integer', Rule::exists('salones', 'id')],
                'codigo' => ['required', 'string', 'max:30', Rule::unique('mesas', 'codigo')->where('salon_id', $datos['salonId'])->ignore($id)],
                'capacidad' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'activo' => ['required', 'boolean'],
            ], [], ['salonId' => 'salón', 'codigo' => 'número o nombre de mesa', 'capacidad' => 'capacidad'])->validate();
            $salon = Salon::query()->whereKey($validado['salonId'])->firstOrFail();
            if ($validado['activo'] && ! $salon->activo) {
                throw ValidationException::withMessages(['salonId' => 'Activa el salón antes de habilitar sus mesas.']);
            }
            $antes = $mesa->only($mesa->getFillable());
            $mesa->fill([
                'salon_id' => $salon->id, 'codigo' => $validado['codigo'],
                'capacidad' => $validado['capacidad'] === '' ? null : $validado['capacidad'],
                'activo' => $validado['activo'],
            ])->save();
            $this->auditar($actor, $mesa, $antes, $id ? 'Mesa actualizada' : 'Mesa registrada');

            return $mesa;
        });
    }

    public function cambiarEstadoSalon(User $actor, int $id, bool $activo): Salon
    {
        return $this->transaccion($actor, function () use ($actor, $id, $activo): Salon {
            $salon = Salon::query()->lockForUpdate()->findOrFail($id);
            if (! $activo) {
                $this->comprobarSalonLibre($salon);
            }
            $antes = $salon->only($salon->getFillable());
            $salon->update(['activo' => $activo]);
            $this->auditar($actor, $salon, $antes, $activo ? 'Salón activado' : 'Salón desactivado');

            return $salon;
        });
    }

    public function cambiarEstadoMesa(User $actor, int $id, bool $activo): Mesa
    {
        return $this->transaccion($actor, function () use ($actor, $id, $activo): Mesa {
            $mesa = Mesa::query()->lockForUpdate()->findOrFail($id);
            $this->comprobarMesaLibre($mesa);
            if ($activo && ! $mesa->salon->activo) {
                throw ValidationException::withMessages(['seguridad' => 'Activa el salón antes de habilitar esta mesa.']);
            }
            $antes = $mesa->only($mesa->getFillable());
            $mesa->update(['activo' => $activo]);
            $this->auditar($actor, $mesa, $antes, $activo ? 'Mesa activada' : 'Mesa desactivada');

            return $mesa;
        });
    }

    private function comprobarMesaLibre(Mesa $mesa): void
    {
        if ($mesa->ocupacion()->exists() || $mesa->pedidos()->whereNotIn('estado', [EstadoPedido::CERRADO->value, EstadoPedido::ANULADO->value])->exists()) {
            throw ValidationException::withMessages(['seguridad' => 'La mesa tiene una atención en curso. Ciérrala y libérala desde el POS antes de modificarla.']);
        }
    }

    private function comprobarSalonLibre(Salon $salon): void
    {
        foreach ($salon->mesas()->orderBy('id')->lockForUpdate()->get() as $mesa) {
            $this->comprobarMesaLibre($mesa);
        }
    }

    /** @param array<string, mixed> $antes */
    private function auditar(User $actor, Model $modelo, array $antes, string $descripcion): void
    {
        activity()->causedBy($actor)->performedOn($modelo)->event('configuracion_restaurante')
            ->withProperties(['antes' => $antes, 'despues' => $modelo->only($modelo->getFillable())])->log($descripcion);
    }

    /**
     * @template T of Salon|Mesa
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    private function transaccion(User $actor, Closure $operacion): Salon|Mesa
    {
        Gate::forUser($actor)->authorize('configuracion.restaurante');
        try {
            return DB::transaction(function () use ($actor, $operacion) {
                $actual = User::query()->findOrFail($actor->id);
                abort_unless($actual->activo, 403);
                Gate::forUser($actual)->authorize('configuracion.restaurante');
                // Un orden de bloqueo compartido para cambios de salón y de mesa.
                // El futuro POS deberá bloquear salón y mesa antes de abrir una atención.
                Salon::query()->orderBy('id')->lockForUpdate()->get();

                return $operacion();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['codigo' => 'Este código ya está registrado.']);
        }
    }
}
