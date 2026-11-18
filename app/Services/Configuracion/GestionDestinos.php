<?php

namespace App\Services\Configuracion;

use App\Models\AreaPreparacion;
use App\Models\EnvioCocina;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionDestinos
{
    use BloqueaImpresion;

    /** @param array{codigo: string, nombre: string, activo: bool} $datos */
    public function guardar(User $actor, ?int $id, array $datos): AreaPreparacion
    {
        return $this->transaccion($actor, 'configuracion.restaurante', function () use ($actor, $id, $datos): AreaPreparacion {
            $destino = $id ? AreaPreparacion::query()->findOrFail($id) : new AreaPreparacion;
            $datos['codigo'] = Str::lower(trim($datos['codigo']));
            $datos['nombre'] = Str::squish($datos['nombre']);
            $validado = Validator::make($datos, [
                'codigo' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/', Rule::unique('areas_preparacion', 'codigo')->ignore($id)],
                'nombre' => ['required', 'string', 'max:100'], 'activo' => ['required', 'boolean'],
            ], ['codigo.regex' => 'Usa letras sin tildes, números o guiones.'], ['codigo' => 'código', 'nombre' => 'nombre'])->validate();
            if ($id && $destino->codigo !== $validado['codigo']) {
                throw ValidationException::withMessages(['codigo' => 'El código se conserva después de crear el destino.']);
            }
            if ($id && ! $validado['activo']) {
                $this->puedeDesactivar($destino);
            }
            $antes = $destino->only($destino->getFillable());
            $destino->fill($validado)->save();
            $this->auditar($actor, $destino, $antes);

            return $destino;
        });
    }

    public function cambiarEstado(User $actor, int $id, bool $activo): AreaPreparacion
    {
        return $this->transaccion($actor, 'configuracion.restaurante', function () use ($actor, $id, $activo): AreaPreparacion {
            $destino = AreaPreparacion::query()->findOrFail($id);
            if (! $activo) {
                $this->puedeDesactivar($destino);
            }
            $antes = $destino->only($destino->getFillable());
            $destino->update(['activo' => $activo]);
            $this->auditar($actor, $destino, $antes);

            return $destino;
        });
    }

    private function puedeDesactivar(AreaPreparacion $destino): void
    {
        if (Producto::query()->where('area_preparacion_id', $destino->id)->where('activo', true)->exists()) {
            throw ValidationException::withMessages(['seguridad' => 'Hay productos activos asignados a este destino. Reasígnalos o desactívalos antes de continuar.']);
        }
        if (EnvioCocina::query()->where('area_preparacion_id', $destino->id)->whereHas('detalles', fn (Builder $query) => $query
            ->whereRaw('cantidad_entregada + cantidad_anulada < cantidad'))->exists()) {
            throw ValidationException::withMessages(['seguridad' => 'Este destino tiene productos pendientes de preparación o entrega.']);
        }
        foreach ($destino->impresoras as $impresora) {
            app(GestionImpresoras::class)->comprobarDisponible($impresora);
        }
    }

    /** @param array<string, mixed> $antes */
    private function auditar(User $actor, AreaPreparacion $destino, array $antes): void
    {
        activity()->causedBy($actor)->performedOn($destino)->event('destino_configurado')
            ->withProperties(['antes' => $antes, 'despues' => $destino->only($destino->getFillable())])->log('Destino de preparación configurado');
    }
}
