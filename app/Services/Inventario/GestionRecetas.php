<?php

namespace App\Services\Inventario;

use App\Models\Articulo;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\RecetaDetalle;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionRecetas
{
    use TransaccionCatalogo;

    /** @param array<string, array{articuloId: string, cantidad: string}> $filas */
    public function guardar(User $actor, int $productoId, string $rendimiento, string $observaciones, bool $activo, array $filas, string $version): Receta
    {
        return $this->transaccion($actor, 'recetas.gestionar', function () use ($actor, $productoId, $rendimiento, $observaciones, $activo, $filas, $version): Receta {
            $producto = Producto::query()->with('articulo')->findOrFail($productoId);
            $this->comprobarProducto($producto, false);
            $receta = Receta::query()->with('detalles')->where('producto_id', $productoId)->first();
            if (! hash_equals($receta ? self::huella($receta) : '', $version)) {
                throw ValidationException::withMessages(['seguridad' => 'La receta cambió en otra sesión. Vuelve a abrirla antes de guardar.']);
            }
            $cantidad = CalculoProduccion::cantidad($rendimiento, 'rendimiento');
            Validator::make(['observaciones' => $observaciones, 'ingredientes' => $filas], [
                'observaciones' => ['nullable', 'string', 'max:2000'], 'ingredientes' => ['required', 'array', 'min:1', 'max:50'],
                'ingredientes.*.articuloId' => ['required', 'integer', 'distinct', Rule::exists('articulos', 'id')],
                'ingredientes.*.cantidad' => ['required', 'string'],
            ], [], ['ingredientes' => 'ingredientes', 'ingredientes.*.articuloId' => 'ingrediente'])->validate();
            $salida = $producto->articulo;
            abort_unless($salida instanceof Articulo, 422);
            $nuevos = [];
            foreach ($filas as $clave => $fila) {
                $articulo = Articulo::query()->with('unidadMedida')->whereKey($fila['articuloId'])->firstOrFail();
                if ($articulo->id === $salida->id || ($activo && (! $articulo->activo || ! $articulo->unidadMedida->activo))) {
                    throw ValidationException::withMessages(['ingredientes.'.$clave.'.articuloId' => 'Selecciona un artículo activo distinto del producto que se obtiene.']);
                }
                $nuevos[$articulo->id] = CalculoProduccion::cantidad($fila['cantidad'], 'ingredientes.'.$clave.'.cantidad');
            }
            $this->comprobarCiclos($salida->id, $nuevos, $receta?->id);
            $antes = $receta ? ['rendimiento' => $receta->rendimiento, 'activo' => $receta->activo, 'observaciones' => $receta->observaciones, 'detalles' => $receta->detalles->toArray()] : [];
            $receta ??= new Receta(['producto_id' => $productoId]);
            $receta->fill(['rendimiento' => $cantidad, 'observaciones' => trim($observaciones) ?: null, 'activo' => $activo])->save();
            $receta->detalles()->delete();
            foreach ($nuevos as $articuloId => $consumo) {
                $receta->detalles()->create(['articulo_id' => $articuloId, 'cantidad' => $consumo]);
            }
            activity()->causedBy($actor)->performedOn($receta)->event('receta_configurada')
                ->withProperties(['antes' => $antes, 'despues' => ['rendimiento' => $cantidad, 'activo' => $activo, 'observaciones' => trim($observaciones), 'detalles' => $receta->detalles()->get()->toArray()]])->log('Receta configurada');

            return $receta;
        });
    }

    public function comprobarProducto(Producto $producto, bool $paraProducir = true): void
    {
        if (! $producto->articulo || $producto->articulo->tipo->value !== 'preparado') {
            throw ValidationException::withMessages(['productoId' => 'Solo los productos de preparación diaria pueden tener receta y producirse.']);
        }
        if ($paraProducir && (! $producto->activo || ! $producto->articulo->activo || ! $producto->articulo->unidadMedida->activo
            || ($producto->areaPreparacion && ! $producto->areaPreparacion->activo))) {
            throw ValidationException::withMessages(['productoId' => 'Activa el producto, su unidad y su destino antes de preparar.']);
        }
    }

    public static function huella(Receta $receta): string
    {
        return hash('sha256', json_encode([$receta->id, $receta->rendimiento, $receta->activo, $receta->observaciones,
            $receta->detalles()->orderBy('articulo_id')->get()->map(fn (RecetaDetalle $fila) => [$fila->articulo_id, $fila->cantidad])->all()], JSON_THROW_ON_ERROR));
    }

    /** @param array<int, numeric-string> $nuevos */
    private function comprobarCiclos(int $salidaId, array $nuevos, ?int $recetaId): void
    {
        $grafo = [];
        foreach (Receta::query()->with(['producto', 'detalles'])->when($recetaId, fn ($query) => $query->whereKeyNot($recetaId))->get() as $receta) {
            if ($receta->producto->articulo_id !== null) {
                $grafo[$receta->producto->articulo_id] = $receta->detalles->pluck('articulo_id')->all();
            }
        }
        $pendientes = array_keys($nuevos);
        $visitados = [];
        while ($pendientes !== []) {
            $id = array_pop($pendientes);
            if ($id === $salidaId) {
                throw ValidationException::withMessages(['ingredientes' => 'La receta produciría una dependencia circular entre preparaciones.']);
            }
            if (! isset($visitados[$id])) {
                $visitados[$id] = true;
                array_push($pendientes, ...($grafo[$id] ?? []));
            }
        }
    }
}
