<?php

namespace App\Services\Inventario;

use App\Enums\TipoProducto;
use App\Models\Producto;

class Disponibilidad
{
    public function consultar(Producto $producto): string
    {
        if (! $producto->activo || ($producto->areaPreparacion && ! $producto->areaPreparacion->activo)) {
            return '0';
        }
        if ($producto->tipo !== TipoProducto::COMBO) {
            return $producto->articulo?->activo ? $producto->articulo->disponible : '0';
        }
        $minimo = null;
        foreach ($producto->componentes as $fila) {
            $componente = $fila->producto;
            if (! $componente || $componente->tipo === TipoProducto::COMBO || ! $componente->activo || ! $componente->articulo?->activo
                || ($componente->areaPreparacion && ! $componente->areaPreparacion->activo) || bccomp($fila->cantidad, '0', 4) <= 0) {
                return '0';
            }
            $cantidad = bcdiv($componente->articulo->disponible, $fila->cantidad, 0);
            $minimo = $minimo === null || bccomp($cantidad, $minimo, 0) < 0 ? $cantidad : $minimo;
        }

        return $minimo ?? '0';
    }
}
