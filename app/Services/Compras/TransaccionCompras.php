<?php

namespace App\Services\Compras;

use App\Models\Proveedor;
use App\Models\User;
use App\Services\Inventario\TransaccionCatalogo;
use Closure;

trait TransaccionCompras
{
    use TransaccionCatalogo;

    /**
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    protected function ejecutar(User $actor, string $permiso, Closure $operacion): mixed
    {
        return $this->transaccion($actor, $permiso, function () use ($operacion) {
            Proveedor::query()->orderBy('id')->lockForUpdate()->get();

            return $operacion();
        });
    }
}
