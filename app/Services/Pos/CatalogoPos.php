<?php

namespace App\Services\Pos;

use App\Models\Producto;
use App\Services\Inventario\CalculoProduccion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class CatalogoPos
{
    /** @return Builder<Producto> */
    public function consulta(): Builder
    {
        return Producto::with(['categoria', 'areaPreparacion', 'articulo.unidadMedida', 'componentes.producto.articulo.unidadMedida', 'componentes.producto.areaPreparacion'])
            ->where('activo', true)->where('visible_pos', true)->whereHas('categoria', fn ($q) => $q->where('activo', true))->orderBy('nombre');
    }

    public function comprobar(Producto $producto, bool $principal = true): void
    {
        if (! $producto->activo || ($principal && (! $producto->visible_pos || ! $producto->categoria?->activo))
            || ($producto->areaPreparacion && ! $producto->areaPreparacion->activo)) {
            throw ValidationException::withMessages(['filas' => 'El producto '.$producto->nombre.' no está habilitado para esta atención.']);
        }
        if ($producto->tipo->value !== 'combo') {
            if (! $producto->articulo?->activo || ! $producto->articulo->unidadMedida->activo) {
                throw ValidationException::withMessages(['filas' => 'Revisa las existencias y la unidad de '.$producto->nombre.'.']);
            }
        } elseif ($producto->componentes->isEmpty()) {
            throw ValidationException::withMessages(['filas' => 'El combo no tiene componentes.']);
        }
    }

    /** @return numeric-string */
    public function disponible(Producto $producto): string
    {
        try {
            $this->comprobar($producto);
            if ($producto->tipo->value !== 'combo') {
                return $producto->articulo->disponible;
            }
            $minimo = null;
            foreach ($producto->componentes as $fila) {
                $this->comprobar($fila->producto, false);
                if ($fila->producto->tipo->value === 'combo') {
                    return '0';
                }
                $factor = CalculoProduccion::cantidad($fila->cantidad);
                $valor = bcdiv($fila->producto->articulo->disponible, $factor, 0);
                $minimo = $minimo === null || bccomp($valor, $minimo, 0) < 0 ? $valor : $minimo;
            }

            return $minimo ?? '0';
        } catch (ValidationException) {
            return '0';
        }
    }
}
