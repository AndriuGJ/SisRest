<?php

namespace App\Services\Configuracion;

use App\Models\AreaPreparacion;
use App\Models\Caja;
use App\Models\Impresora;
use App\Models\PruebaImpresion;
use App\Models\TrabajoImpresion;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

trait BloqueaImpresion
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
                Impresora::query()->orderBy('id')->lockForUpdate()->get();
                Caja::query()->orderBy('id')->lockForUpdate()->get();
                $actual = User::query()->findOrFail($actor->id);
                abort_unless($actual->activo, 403);
                Gate::forUser($actual)->authorize($permiso);
                // Un proceso interrumpido no se vuelve a enviar automáticamente.
                PruebaImpresion::query()->where('estado', 'procesando')->where('updated_at', '<', now()->subMinute())
                    ->update(['estado' => 'resultado_incierto', 'mensaje' => 'La prueba se interrumpió. Revisa el papel antes de solicitar otra.']);

                TrabajoImpresion::query()->where('estado', 'procesando')->where('updated_at', '<', now()->subMinute())
                    ->update(['estado' => 'resultado_incierto', 'ultimo_error' => 'El proceso se interrumpió. Revisa el papel antes de solicitar una copia.']);

                return $operacion();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['codigo' => 'El código o la asignación ya está registrado.']);
        }
    }
}
