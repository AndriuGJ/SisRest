<?php

namespace App\Services\Inventario;

use App\Models\Articulo;
use App\Models\CompraDetalle;
use App\Models\PresentacionArticulo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Presentaciones
{
    public const DECIMAL = '/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,4})?$/';

    /** @param array<string, array{id: string, nombre: string, factor: string, activo: bool}> $filas */
    public function guardar(Articulo $articulo, array $filas): void
    {
        Validator::make(['presentaciones' => $filas], [
            'presentaciones' => ['array', 'max:20'],
            'presentaciones.*.id' => ['present', 'nullable', 'integer', 'distinct'],
            'presentaciones.*.nombre' => ['required', 'string', 'max:100'],
            'presentaciones.*.factor' => ['required', 'regex:'.self::DECIMAL, 'numeric', 'gt:0'],
            'presentaciones.*.activo' => ['required', 'boolean'],
        ], ['presentaciones.*.factor.regex' => 'Usa una cantidad positiva con hasta cuatro decimales.'], [
            'presentaciones.*.nombre' => 'nombre de presentación', 'presentaciones.*.factor' => 'equivalencia',
        ])->validate();
        $actuales = $articulo->presentaciones()->get();
        $vistos = [];
        $conservadas = [];
        foreach ($filas as $clave => $fila) {
            $presentacion = $fila['id'] !== ''
                ? $articulo->presentaciones()->whereKey($fila['id'])->first() : new PresentacionArticulo;
            if (! $presentacion) {
                throw ValidationException::withMessages(['presentaciones.'.$clave.'.id' => 'La presentación no pertenece a este artículo.']);
            }
            $nombre = Str::lower(Str::squish($fila['nombre']));
            if (in_array($nombre, $vistos, true)) {
                throw ValidationException::withMessages(['presentaciones.'.$clave.'.nombre' => 'No repitas el nombre de una presentación.']);
            }
            $vistos[] = $nombre;
            if ($actuales->contains(fn (PresentacionArticulo $otra) => $otra->id !== $presentacion->id && Str::lower(Str::squish($otra->nombre)) === $nombre)) {
                throw ValidationException::withMessages(['presentaciones.'.$clave.'.nombre' => 'Esta presentación ya existe. Edita o activa la presentación registrada.']);
            }
            $factor = $fila['factor'];
            if (! is_numeric($factor)) {
                throw ValidationException::withMessages(['presentaciones.'.$clave.'.factor' => 'La equivalencia debe ser numérica.']);
            }
            if ($presentacion->exists && bccomp($presentacion->factor_conversion, $factor, 4) !== 0
                && CompraDetalle::query()->where('presentacion_articulo_id', $presentacion->id)->exists()) {
                throw ValidationException::withMessages(['presentaciones.'.$clave.'.factor' => 'Esta presentación ya se usó en compras. Crea otra para cambiar la equivalencia.']);
            }
            $presentacion->fill(['articulo_id' => $articulo->id, 'nombre' => $nombre, 'factor_conversion' => $fila['factor'], 'activo' => $fila['activo']])->save();
            $conservadas[] = $presentacion->id;
        }
        $articulo->presentaciones()->whereNotIn('id', $conservadas)->update(['activo' => false]);
    }
}
