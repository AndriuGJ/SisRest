<?php

namespace App\Services\Inventario;

use App\Models\Articulo;
use App\Models\RecetaDetalle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionInsumos
{
    use TransaccionCatalogo;

    /**
     * @param  array{codigo: string, nombre: string, unidadId: string, minimo: string, activo: bool}  $datos
     * @param  array<string, array{id: string, nombre: string, factor: string, activo: bool}>  $presentaciones
     */
    public function guardar(User $actor, ?int $id, array $datos, array $presentaciones): Articulo
    {
        return $this->transaccion($actor, $id ? 'insumos.editar' : 'insumos.crear', function () use ($actor, $id, $datos, $presentaciones): Articulo {
            $insumo = $id ? Articulo::query()->where('tipo', 'insumo')->findOrFail($id) : new Articulo;
            $datos['codigo'] = Str::upper(trim($datos['codigo']));
            $datos['nombre'] = Str::squish($datos['nombre']);
            $validado = Validator::make($datos, [
                'codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9._-]+$/', Rule::unique('articulos', 'codigo')->ignore($id)],
                'nombre' => ['required', 'string', 'max:150'],
                'unidadId' => ['required', 'integer', Rule::exists('unidades_medida', 'id')->where('activo', true)],
                'minimo' => ['required', 'regex:'.Presentaciones::DECIMAL], 'activo' => ['required', 'boolean'],
            ], ['codigo.regex' => 'Usa letras sin tildes, números, punto o guion.', 'minimo.regex' => 'Usa una cantidad no negativa con hasta cuatro decimales.'], [
                'codigo' => 'código', 'nombre' => 'nombre', 'unidadId' => 'unidad de inventario', 'minimo' => 'existencia mínima',
            ])->validate();
            if ($id && ($insumo->codigo !== $validado['codigo'] || (string) $insumo->unidad_medida_id !== $validado['unidadId'])) {
                throw ValidationException::withMessages(['seguridad' => 'El código y la unidad de inventario se conservan después de crear el insumo.']);
            }
            if (! $validado['activo'] && $id) {
                $this->comprobarDesactivacion($insumo);
            }
            $antes = $insumo->presentaciones()->get()->toArray();
            $insumo->fill(['codigo' => $validado['codigo'], 'nombre' => $validado['nombre'], 'tipo' => 'insumo',
                'unidad_medida_id' => $validado['unidadId'], 'stock_minimo' => $validado['minimo'], 'activo' => $validado['activo']])->save();
            app(Presentaciones::class)->guardar($insumo, $presentaciones);
            activity()->causedBy($actor)->performedOn($insumo)->event('insumo_configurado')
                ->withProperties(['presentaciones_antes' => $antes, 'presentaciones_despues' => $insumo->presentaciones()->get()->toArray()])->log($id ? 'Insumo actualizado' : 'Insumo registrado');

            return $insumo;
        });
    }

    public function cambiarEstado(User $actor, int $id, bool $activo): Articulo
    {
        return $this->transaccion($actor, 'insumos.editar', function () use ($actor, $id, $activo): Articulo {
            $insumo = Articulo::query()->where('tipo', 'insumo')->findOrFail($id);
            if (! $activo) {
                $this->comprobarDesactivacion($insumo);
            }
            $insumo->update(['activo' => $activo]);
            activity()->causedBy($actor)->performedOn($insumo)->event('insumo_configurado')->log($activo ? 'Insumo activado' : 'Insumo desactivado');

            return $insumo;
        });
    }

    public function comprobarDesactivacion(Articulo $articulo): void
    {
        if (bccomp($articulo->stock_reservado, '0', 4) > 0) {
            throw ValidationException::withMessages(['seguridad' => 'El artículo tiene cantidades reservadas. Resuelve la atención antes de desactivarlo.']);
        }
        if (RecetaDetalle::query()->where('articulo_id', $articulo->id)->whereHas('receta', fn (Builder $query) => $query
            ->where('activo', true)->whereHas('producto', fn (Builder $producto) => $producto->where('activo', true)))->exists()) {
            throw ValidationException::withMessages(['seguridad' => 'El artículo se utiliza en recetas activas. Revisa esas recetas antes de desactivarlo.']);
        }
    }
}
