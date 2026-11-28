<?php

namespace App\Services\Inventario;

use App\Enums\TipoProducto;
use App\Models\AreaPreparacion;
use App\Models\Articulo;
use App\Models\CategoriaProducto;
use App\Models\ComboComponente;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class GestionProductos
{
    use TransaccionCatalogo;

    /**
     * @param  array{codigo: string, nombre: string, descripcion: string, tipo: string, origen: string, unidadId: string, categoriaId: string, destinoId: string, precio: string, minimo: string, visible: bool, activo: bool, quitarFoto: bool}  $datos
     * @param  array<string, array{productoId: string, cantidad: string}>  $componentes
     * @param  array<string, array{id: string, nombre: string, factor: string, activo: bool}>  $presentaciones
     */
    public function guardar(User $actor, ?int $id, array $datos, array $componentes, array $presentaciones, ?UploadedFile $foto = null): Producto
    {
        $nuevaRuta = null;
        $anterior = null;
        try {
            $resultado = $this->transaccion($actor, $id ? 'productos.editar' : 'productos.crear', function () use ($actor, $id, $datos, $componentes, $presentaciones, $foto, &$nuevaRuta, &$anterior): Producto {
                $producto = $id ? Producto::query()->with('articulo')->findOrFail($id) : new Producto;
                $datos['codigo'] = Str::upper(trim($datos['codigo']));
                $datos['nombre'] = Str::squish($datos['nombre']);
                $combo = $datos['tipo'] === 'combo';
                $validado = Validator::make($datos + ['foto' => $foto], [
                    'codigo' => ['required', 'string', 'max:'.($id ? '40' : '37'), 'regex:/^[A-Z0-9._-]+$/', Rule::unique('productos', 'codigo')->ignore($id)],
                    'nombre' => ['required', 'string', 'max:150'], 'descripcion' => ['nullable', 'string', 'max:2000'],
                    'tipo' => ['required', Rule::enum(TipoProducto::class)],
                    'origen' => $combo ? ['nullable'] : ['required', Rule::in(['preparado', 'reventa'])],
                    'unidadId' => $combo ? ['nullable'] : ['required', 'integer', Rule::exists('unidades_medida', 'id')->where('magnitud', 'unidad')->where('activo', true)],
                    'categoriaId' => ['required', 'integer', Rule::exists('categorias_producto', 'id')],
                    'destinoId' => ['nullable', 'integer', Rule::exists('areas_preparacion', 'id')],
                    'precio' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'],
                    'minimo' => ['required', 'regex:'.Presentaciones::DECIMAL],
                    'quitarFoto' => ['required', 'boolean'], 'visible' => ['required', 'boolean'], 'activo' => ['required', 'boolean'],
                    'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
                ], ['precio.regex' => 'Usa un importe no negativo con hasta dos decimales.', 'minimo.regex' => 'Usa una cantidad no negativa con hasta cuatro decimales.'], [
                    'codigo' => 'código', 'nombre' => 'nombre', 'tipo' => 'tipo', 'origen' => 'origen de existencias', 'unidadId' => 'unidad de venta',
                    'categoriaId' => 'categoría', 'destinoId' => 'destino', 'precio' => 'precio final', 'minimo' => 'existencia mínima', 'foto' => 'fotografía',
                ])->validate();
                if ($id && ($producto->codigo !== $validado['codigo'] || $producto->tipo->value !== $validado['tipo'])) {
                    throw ValidationException::withMessages(['seguridad' => 'El código y tipo de producto se conservan después de crear.']);
                }
                if (! $combo && (($validado['tipo'] === 'reventa' && $validado['origen'] !== 'reventa')
                    || (in_array($validado['tipo'], ['plato', 'menu'], true) && $validado['origen'] !== 'preparado'))) {
                    throw ValidationException::withMessages(['origen' => 'Los platos y menús se preparan; la reventa se compra lista para vender.']);
                }
                if ($id && ! $validado['activo']) {
                    $this->comprobarDesactivacion($producto);
                }
                if ($validado['activo']) {
                    $this->validarReferencias($validado['categoriaId'], $combo ? '' : $validado['destinoId']);
                }
                $anteriores = $producto->exists ? $producto->componentes()->get()->toArray() : [];
                if (! $combo) {
                    $articulo = $producto->articulo ?? new Articulo;
                    if ($articulo->exists && ($articulo->tipo->value !== $validado['origen'] || (string) $articulo->unidad_medida_id !== $validado['unidadId'])) {
                        throw ValidationException::withMessages(['seguridad' => 'El origen y la unidad de existencias se conservan después de crear el producto.']);
                    }
                    if (! $articulo->exists && Articulo::query()->where('codigo', 'PV-'.$validado['codigo'])->exists()) {
                        throw ValidationException::withMessages(['codigo' => 'Este código coincide con otro registro de existencias. Usa otro código.']);
                    }
                    $articulo->fill(['nombre' => $validado['nombre'], 'tipo' => $validado['origen'], 'unidad_medida_id' => $validado['unidadId'],
                        'stock_minimo' => $validado['minimo'], 'activo' => $validado['activo']]);
                    if (! $articulo->exists) {
                        $articulo->codigo = 'PV-'.$validado['codigo'];
                    }
                    $articulo->save();
                    app(Presentaciones::class)->guardar($articulo, $presentaciones);
                } else {
                    $articulo = null;
                    $this->validarComponentes($id, $componentes, $validado['activo']);
                }
                $anterior = $producto->imagen_ruta;
                if ($foto) {
                    $nuevaRuta = $foto->store('productos', 'public');
                    if (! is_string($nuevaRuta)) {
                        throw ValidationException::withMessages(['foto' => 'No se pudo guardar la fotografía.']);
                    }
                }
                $producto->fill(['codigo' => $validado['codigo'], 'nombre' => $validado['nombre'], 'descripcion' => trim($validado['descripcion'] ?? '') ?: null,
                    'tipo' => $validado['tipo'], 'categoria_producto_id' => $validado['categoriaId'], 'articulo_id' => $articulo?->id,
                    'area_preparacion_id' => $combo ? null : ($validado['destinoId'] ?: null), 'precio_venta' => $validado['precio'],
                    'visible_pos' => $validado['visible'], 'activo' => $validado['activo'],
                    'imagen_ruta' => $nuevaRuta ?? ($datos['quitarFoto'] ? null : $anterior)])->save();
                if ($combo) {
                    $producto->componentes()->delete();
                    foreach ($componentes as $fila) {
                        $producto->componentes()->create(['producto_id' => $fila['productoId'], 'cantidad' => $fila['cantidad']]);
                    }
                }
                activity()->causedBy($actor)->performedOn($producto)->event('producto_configurado')
                    ->withProperties(['componentes_antes' => $anteriores, 'componentes_despues' => $producto->componentes()->get()->toArray(),
                        'presentaciones' => $articulo?->presentaciones()->get()->toArray() ?? []])->log($id ? 'Producto actualizado' : 'Producto registrado');

                return $producto;
            });
        } catch (Throwable $error) {
            if (is_string($nuevaRuta)) {
                Storage::disk('public')->delete($nuevaRuta);
            }
            throw $error;
        }
        if (($nuevaRuta !== null || $datos['quitarFoto']) && $anterior && $anterior !== $nuevaRuta && str_starts_with($anterior, 'productos/')) {
            Storage::disk('public')->delete($anterior);
        }

        return $resultado;
    }

    private function validarReferencias(string $categoriaId, string $destinoId): void
    {
        if (! CategoriaProducto::query()->whereKey($categoriaId)->where('activo', true)->exists()) {
            throw ValidationException::withMessages(['categoriaId' => 'Selecciona una categoría activa.']);
        }
        if ($destinoId !== '' && ! AreaPreparacion::query()->whereKey($destinoId)->where('activo', true)->exists()) {
            throw ValidationException::withMessages(['destinoId' => 'Selecciona un destino activo o entrega directa.']);
        }
    }

    /** @param array<string, array{productoId: string, cantidad: string}> $filas */
    private function validarComponentes(?int $id, array $filas, bool $activo): void
    {
        Validator::make(['componentes' => $filas], [
            'componentes' => ['array', 'max:20', $activo ? 'min:1' : 'min:0'],
            'componentes.*.productoId' => ['required', 'integer', 'distinct', Rule::exists('productos', 'id')->whereNot('tipo', 'combo')],
            'componentes.*.cantidad' => ['required', 'regex:'.Presentaciones::DECIMAL, 'numeric', 'gt:0'],
        ], [], ['componentes' => 'componentes del combo', 'componentes.*.productoId' => 'producto componente', 'componentes.*.cantidad' => 'cantidad'])->validate();
        foreach ($filas as $clave => $fila) {
            $componente = Producto::query()->with(['articulo', 'areaPreparacion', 'categoria'])->whereKey($fila['productoId'])->firstOrFail();
            if ($componente->id === $id || ($activo && (! $componente->activo || ! $componente->articulo?->activo || ($componente->areaPreparacion && ! $componente->areaPreparacion->activo)))) {
                throw ValidationException::withMessages(['componentes.'.$clave.'.productoId' => 'Elige un producto activo, con existencias habilitadas y destino activo. No se permiten combos anidados.']);
            }
        }
    }

    public function cambiarEstado(User $actor, int $id, bool $activo): Producto
    {
        return $this->transaccion($actor, 'productos.editar', function () use ($actor, $id, $activo): Producto {
            $producto = Producto::query()->with('articulo')->findOrFail($id);
            if (! $activo) {
                $this->comprobarDesactivacion($producto);
            } else {
                $this->validarReferencias((string) $producto->categoria_producto_id, (string) ($producto->area_preparacion_id ?? ''));
                if ($producto->tipo === TipoProducto::COMBO) {
                    $filas = $producto->componentes->mapWithKeys(fn (ComboComponente $fila) => ['c-'.$fila->id => ['productoId' => (string) $fila->producto_id, 'cantidad' => $fila->cantidad]])->all();
                    $this->validarComponentes($id, $filas, true);
                }
            }
            $producto->update(['activo' => $activo]);
            $producto->articulo?->update(['activo' => $activo]);
            activity()->causedBy($actor)->performedOn($producto)->event('producto_configurado')->log($activo ? 'Producto activado' : 'Producto desactivado');

            return $producto;
        });
    }

    private function comprobarDesactivacion(Producto $producto): void
    {
        if ($producto->articulo) {
            app(GestionInsumos::class)->comprobarDesactivacion($producto->articulo);
        }
        if (ComboComponente::query()->where('producto_id', $producto->id)->whereHas('combo', fn (Builder $query) => $query->where('activo', true))->exists()) {
            throw ValidationException::withMessages(['seguridad' => 'El producto forma parte de combos activos. Revisa esos combos antes de desactivarlo.']);
        }
        if (PedidoDetalle::query()->where('producto_id', $producto->id)->where('estado', '<>', 'anulado')->whereRaw('cantidad_anulada < cantidad')
            ->whereHas('pedido', fn (Builder $query) => $query->whereNotIn('estado', ['cerrado', 'anulado']))->exists()) {
            throw ValidationException::withMessages(['seguridad' => 'El producto tiene pedidos en curso. Resuelve la atención antes de desactivarlo.']);
        }
    }
}
