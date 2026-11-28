<?php

namespace App\Services\Inventario;

use App\Enums\EstadoPreparacion;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\Preparacion;
use App\Models\Producto;
use App\Models\User;
use App\Support\Cantidad;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionPreparaciones
{
    use TransaccionCatalogo;

    /** @return array{productoId: int, recetaId: int, recetaHuella: string, calculoHash: string, rendimiento: numeric-string, cantidad: numeric-string, nombre: string, unidad: string, ingredientes: array<int, array{articuloId: int, nombre: string, unidad: string, estandar: numeric-string, disponible: numeric-string}>} */
    public function previsualizar(User $actor, int $productoId, string $cantidad): array
    {
        return $this->transaccion($actor, 'preparaciones.crear', fn () => $this->calcular($productoId, $cantidad));
    }

    /** @return array{productoId: int, recetaId: int, recetaHuella: string, calculoHash: string, rendimiento: numeric-string, cantidad: numeric-string, nombre: string, unidad: string, ingredientes: array<int, array{articuloId: int, nombre: string, unidad: string, estandar: numeric-string, disponible: numeric-string}>} */
    private function calcular(int $productoId, string $cantidad): array
    {
        $producida = CalculoProduccion::cantidad($cantidad);
        $producto = Producto::query()->with(['articulo.unidadMedida', 'areaPreparacion', 'receta.detalles.articulo.unidadMedida'])->findOrFail($productoId);
        app(GestionRecetas::class)->comprobarProducto($producto);
        $receta = $producto->receta;
        if (! $receta || ! $receta->activo || $receta->detalles->isEmpty()) {
            throw ValidationException::withMessages(['productoId' => 'Configura una receta activa con ingredientes antes de preparar este producto.']);
        }
        $rendimiento = CalculoProduccion::cantidad($receta->rendimiento, 'rendimiento');
        $filas = [];
        foreach ($receta->detalles as $fila) {
            $articulo = $fila->articulo;
            if (! $articulo->activo || ! $articulo->unidadMedida->activo || $articulo->id === $producto->articulo_id) {
                throw ValidationException::withMessages(['productoId' => 'La receta contiene un ingrediente desactivado o su propio producto de salida. Revisa la receta.']);
            }
            $filas[$articulo->id] = ['articuloId' => $articulo->id, 'nombre' => $articulo->nombre, 'unidad' => $articulo->unidadMedida->codigo,
                'estandar' => CalculoProduccion::escalar(CalculoProduccion::cantidad($fila->cantidad), $producida, $rendimiento), 'disponible' => $articulo->disponible];
        }
        $huella = GestionRecetas::huella($receta);

        return ['productoId' => $producto->id, 'recetaId' => $receta->id, 'recetaHuella' => $huella,
            'calculoHash' => self::huellaCalculo($productoId, $producida, $huella), 'rendimiento' => $rendimiento,
            'cantidad' => $producida, 'nombre' => $producto->nombre, 'unidad' => $producto->articulo->unidadMedida->codigo, 'ingredientes' => $filas];
    }

    public static function huellaCalculo(int $productoId, string $cantidad, string $recetaHuella): string
    {
        return hash('sha256', json_encode([$productoId, $cantidad, $recetaHuella], JSON_THROW_ON_ERROR));
    }

    public static function huellaBorrador(Preparacion $preparacion): string
    {
        return hash('sha256', json_encode([$preparacion->id, $preparacion->estado->value, $preparacion->producto_id,
            $preparacion->fecha->format('Y-m-d'), $preparacion->cantidad_producida, $preparacion->receta_huella,
            $preparacion->observaciones, $preparacion->motivo_ajuste,
            $preparacion->detalles()->orderBy('articulo_id')->get(['articulo_id', 'cantidad_estandar', 'cantidad_utilizada'])->toArray()], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{productoId: string, cantidad: string, fecha: string, observaciones: string, motivoAjuste: string}  $datos
     * @param  array<int|string, string>  $consumos
     */
    public function guardar(User $actor, ?int $id, string $clave, array $datos, array $consumos, string $calculoHash, string $version = ''): Preparacion
    {
        return $this->transaccion($actor, 'preparaciones.crear', function () use ($actor, $id, $clave, $datos, $consumos, $calculoHash, $version): Preparacion {
            Validator::make($datos + ['clave' => $clave], [
                'clave' => ['required', 'uuid'], 'productoId' => ['required', 'integer', Rule::exists('productos', 'id')],
                'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'observaciones' => ['nullable', 'string', 'max:2000'],
                'motivoAjuste' => ['nullable', 'string', 'max:500'],
            ], ['fecha.before_or_equal' => 'La fecha de preparación no puede ser posterior a hoy.'], ['productoId' => 'producto', 'fecha' => 'fecha de preparación', 'motivoAjuste' => 'motivo del ajuste'])->validate();
            if (! $id && ($existente = Preparacion::query()->where('clave_registro', $clave)->first())) {
                abort_unless($existente->usuario_id === $actor->id, 403);

                return $existente;
            }
            $preparacion = $id ? Preparacion::query()->lockForUpdate()->findOrFail($id) : new Preparacion;
            if ($id) {
                $this->comprobarEditor($actor, $preparacion);
                $this->comprobarBorrador($preparacion);
                if (! hash_equals(self::huellaBorrador($preparacion), $version)) {
                    throw ValidationException::withMessages(['seguridad' => 'El borrador cambió en otra sesión. Vuelve a abrirlo antes de guardar.']);
                }
                if ((string) $preparacion->producto_id !== $datos['productoId']) {
                    throw ValidationException::withMessages(['productoId' => 'El producto del borrador se conserva. Crea otra preparación para otro producto.']);
                }
            }
            $producto = Producto::query()->whereKey($datos['productoId'])->firstOrFail();
            $calculo = $this->calcular($producto->id, $datos['cantidad']);
            if (! hash_equals($calculo['calculoHash'], $calculoHash)) {
                throw ValidationException::withMessages(['seguridad' => 'La cantidad o receta cambió. Pulsa Calcular ingredientes para revisar el nuevo consumo.']);
            }
            Validator::make(['consumos' => $consumos], ['consumos' => ['array', 'max:50'], 'consumos.*' => ['required', 'string', 'max:25']])->validate();
            $reales = [];
            $ajustado = false;
            foreach ($consumos as $claveFila => $valor) {
                if (! array_key_exists($claveFila, $calculo['ingredientes'])) {
                    throw ValidationException::withMessages(['consumos' => 'Los consumos deben corresponder a los ingredientes de la receta calculada.']);
                }
            }
            foreach ($calculo['ingredientes'] as $articuloId => $fila) {
                if ($consumos !== [] && ! array_key_exists($articuloId, $consumos)) {
                    throw ValidationException::withMessages(['consumos' => 'Faltan ingredientes en la revisión de consumos. Recalcula la preparación.']);
                }
                $reales[$articuloId] = CalculoProduccion::cantidad($consumos[$articuloId] ?? $fila['estandar'], 'consumos.'.$articuloId, false);
                $ajustado = $ajustado || bccomp($reales[$articuloId], $fila['estandar'], 4) !== 0;
            }
            if ($ajustado) {
                Gate::forUser(User::query()->findOrFail($actor->id))->authorize('preparaciones.ajustar_consumos');
                Validator::make(['motivoAjuste' => trim($datos['motivoAjuste'])], ['motivoAjuste' => ['required', 'string', 'min:5', 'max:500']], [], ['motivoAjuste' => 'motivo del ajuste'])->validate();
            }
            if (! collect($reales)->contains(fn (string $valor) => bccomp(CalculoProduccion::cantidad($valor, 'consumos', false), '0', 4) > 0)) {
                throw ValidationException::withMessages(['consumos' => 'Registra al menos un ingrediente realmente utilizado.']);
            }
            if (! $id) {
                $preparacion->fill(['codigo' => 'PREP-'.Str::ulid(), 'clave_registro' => $clave, 'usuario_id' => $actor->id, 'estado' => 'borrador']);
            }
            $preparacion->fill(['producto_id' => $producto->id, 'receta_id' => $calculo['recetaId'], 'receta_huella' => $calculo['recetaHuella'],
                'rendimiento_receta' => $calculo['rendimiento'], 'producto_nombre' => $calculo['nombre'], 'unidad_codigo' => $calculo['unidad'],
                'cantidad_producida' => $calculo['cantidad'], 'fecha' => $datos['fecha'], 'observaciones' => trim($datos['observaciones']) ?: null,
                'motivo_ajuste' => $ajustado ? trim($datos['motivoAjuste']) : null])->save();
            $preparacion->detalles()->delete();
            foreach ($calculo['ingredientes'] as $articuloId => $fila) {
                $preparacion->detalles()->create(['articulo_id' => $articuloId, 'descripcion' => $fila['nombre'], 'unidad_codigo' => $fila['unidad'],
                    'cantidad_estandar' => $fila['estandar'], 'cantidad_utilizada' => $reales[$articuloId]]);
            }
            activity()->causedBy($actor)->performedOn($preparacion)->event('preparacion_borrador')
                ->withProperties(['consumos' => $reales, 'ajustado' => $ajustado])->log($id ? 'Borrador actualizado' : 'Borrador registrado');

            return $preparacion;
        });
    }

    public function confirmar(User $actor, int $id, string $version): Preparacion
    {
        return $this->transaccion($actor, 'preparaciones.confirmar', function () use ($actor, $id, $version): Preparacion {
            $preparacion = Preparacion::query()->with(['detalles', 'producto.articulo.unidadMedida', 'producto.areaPreparacion', 'receta'])->lockForUpdate()->findOrFail($id);
            if ($preparacion->estado === EstadoPreparacion::CONFIRMADA) {
                return $preparacion;
            }
            $this->comprobarBorrador($preparacion);
            if (! hash_equals(self::huellaBorrador($preparacion), $version)) {
                throw ValidationException::withMessages(['seguridad' => 'El borrador cambió. Revisa los consumos antes de confirmar otra vez.']);
            }
            if (! $preparacion->receta_huella || ! $preparacion->receta?->activo || $preparacion->detalles->isEmpty()) {
                throw ValidationException::withMessages(['seguridad' => 'Recalcula y guarda el borrador con una receta activa antes de confirmar.']);
            }
            app(GestionRecetas::class)->comprobarProducto($preparacion->producto);
            $salida = $preparacion->producto->articulo;
            abort_unless($salida instanceof Articulo, 422);
            if ($salida->unidadMedida->codigo !== $preparacion->unidad_codigo) {
                throw ValidationException::withMessages(['seguridad' => 'La unidad del producto cambió. Revisa el borrador.']);
            }
            $total = '0.000000';
            $consumos = [];
            foreach ($preparacion->detalles as $detalle) {
                $articulo = Articulo::query()->with('unidadMedida')->findOrFail($detalle->articulo_id);
                $cantidad = CalculoProduccion::cantidad($detalle->cantidad_utilizada, 'consumos', false);
                if ($articulo->id === $salida->id || ! $articulo->activo || ! $articulo->unidadMedida->activo || $articulo->unidadMedida->codigo !== $detalle->unidad_codigo) {
                    throw ValidationException::withMessages(['seguridad' => 'Un ingrediente está desactivado o cambió de unidad. Revisa la preparación.']);
                }
                if (bccomp($articulo->disponible, $cantidad, 4) < 0) {
                    throw ValidationException::withMessages(['seguridad' => 'No hay suficiente '.$detalle->descripcion.'. Disponible: '.Cantidad::mostrar($articulo->disponible).' '.$detalle->unidad_codigo.'.']);
                }
                $costo = CalculoProduccion::costo(bcmul($cantidad, $articulo->costo_promedio, 10));
                $total = CalculoProduccion::costo(bcadd($total, $costo, 6));
                $consumos[] = ['articulo' => $articulo, 'detalle' => $detalle, 'cantidad' => $cantidad, 'costo' => $costo];
            }
            if (! collect($consumos)->contains(fn (array $fila) => bccomp(CalculoProduccion::cantidad($fila['cantidad'], 'consumos', false), '0', 4) > 0)) {
                throw ValidationException::withMessages(['seguridad' => 'La preparación necesita al menos un consumo real positivo.']);
            }
            $producida = CalculoProduccion::cantidad($preparacion->cantidad_producida);
            $saldoNuevo = CalculoProduccion::cantidad(bcadd($salida->stock_actual, $producida, 4));
            $valorExistente = bcmul($salida->stock_actual, $salida->costo_promedio, 10);
            $promedio = CalculoProduccion::costo(bcdiv(bcadd($valorExistente, $total, 10), $saldoNuevo, 7));
            $costoLoteUnitario = CalculoProduccion::costo(bcdiv($total, $producida, 7));
            foreach ($consumos as $fila) {
                $articulo = $fila['articulo'];
                $fila['detalle']->update(['costo_unitario' => $articulo->costo_promedio, 'costo_total' => $fila['costo']]);
                if (bccomp($fila['cantidad'], '0', 4) > 0) {
                    $antes = $articulo->stock_actual;
                    $despues = bcsub($antes, $fila['cantidad'], 4);
                    $articulo->update(['stock_actual' => $despues]);
                    $this->movimiento($actor, $preparacion, $articulo, 'consumo_preparacion', bcsub('0', $fila['cantidad'], 4), $antes, $despues, $articulo->costo_promedio);
                }
            }
            $antes = $salida->stock_actual;
            $salida->update(['stock_actual' => $saldoNuevo, 'costo_promedio' => $promedio]);
            $this->movimiento($actor, $preparacion, $salida, 'produccion', $producida, $antes, $saldoNuevo, $costoLoteUnitario);
            $preparacion->update(['estado' => 'confirmada', 'confirmada_at' => now(), 'confirmada_por' => $actor->id, 'costo_total' => $total]);
            activity()->causedBy($actor)->performedOn($preparacion)->event('preparacion_confirmada')->log('Preparación confirmada y existencias actualizadas');

            return $preparacion;
        });
    }

    /**
     * @param  numeric-string  $cantidad
     * @param  numeric-string  $antes
     * @param  numeric-string  $despues
     * @param  numeric-string  $costo
     */
    private function movimiento(User $actor, Preparacion $preparacion, Articulo $articulo, string $tipo, string $cantidad, string $antes, string $despues, string $costo): void
    {
        MovimientoInventario::create(['articulo_id' => $articulo->id, 'usuario_id' => $actor->id, 'tipo' => $tipo,
            'cantidad' => $cantidad, 'saldo_anterior' => $antes, 'saldo_posterior' => $despues, 'costo_unitario' => $costo,
            'origen_type' => Preparacion::class, 'origen_id' => $preparacion->id, 'motivo' => $preparacion->motivo_ajuste,
            'clave_idempotencia' => 'prep:'.$preparacion->id.':'.$tipo.':'.$articulo->id, 'registrado_at' => now()]);
    }

    public function anular(User $actor, int $id, string $motivo, string $version): Preparacion
    {
        return $this->transaccion($actor, 'preparaciones.crear', function () use ($actor, $id, $motivo, $version): Preparacion {
            $preparacion = Preparacion::query()->lockForUpdate()->findOrFail($id);
            $this->comprobarEditor($actor, $preparacion);
            if ($preparacion->estado === EstadoPreparacion::ANULADA) {
                return $preparacion;
            }
            $this->comprobarBorrador($preparacion);
            if (! hash_equals(self::huellaBorrador($preparacion), $version)) {
                throw ValidationException::withMessages(['seguridad' => 'El borrador cambió. Revisa la preparación antes de anularla.']);
            }
            Validator::make(['motivo' => trim($motivo)], ['motivo' => ['required', 'string', 'min:5', 'max:500']])->validate();
            $preparacion->update(['estado' => 'anulada', 'motivo_anulacion' => trim($motivo), 'anulada_por' => $actor->id, 'anulada_at' => now()]);
            activity()->causedBy($actor)->performedOn($preparacion)->event('preparacion_anulada')->log('Borrador anulado sin movimientos de inventario');

            return $preparacion;
        });
    }

    private function comprobarEditor(User $actor, Preparacion $preparacion): void
    {
        abort_unless($preparacion->usuario_id === $actor->id || User::query()->findOrFail($actor->id)->can('preparaciones.confirmar'), 403);
    }

    private function comprobarBorrador(Preparacion $preparacion): void
    {
        if ($preparacion->estado !== EstadoPreparacion::BORRADOR) {
            throw ValidationException::withMessages(['seguridad' => 'Solo los borradores pueden editarse o anularse. Las preparaciones confirmadas conservan sus movimientos.']);
        }
    }
}
