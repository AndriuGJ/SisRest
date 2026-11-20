<?php

namespace App\Services\Inventario;

use App\Models\AreaPreparacion;
use App\Models\Articulo;
use App\Models\Producto;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

trait TransaccionCatalogo
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    protected function transaccion(User $actor, string $permiso, Closure $operacion): mixed
    {
        Gate::forUser($actor)->authorize($permiso);
        try {
            return DB::transaction(function () use ($actor, $permiso, $operacion) {
                AreaPreparacion::query()->orderBy('id')->lockForUpdate()->get();
                Articulo::query()->orderBy('id')->lockForUpdate()->get();
                Producto::query()->orderBy('id')->lockForUpdate()->get();
                $actual = User::query()->findOrFail($actor->id);
                abort_unless($actual->activo, 403);
                Gate::forUser($actual)->authorize($permiso);

                return $operacion();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['codigo' => 'El código o una presentación ya está registrado.']);
        }
    }
}
