<?php

namespace App\Services\Pos;

use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\CobroDetalle;
use App\Models\EnvioCocinaDetalle;
use App\Models\Mesa;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\ReservaInventario;
use App\Models\Salon;
use App\Models\TipoDocumentoIdentidad;
use App\Models\User;
use App\Services\Compras\ImportesCompra;
use App\Services\Inventario\CalculoProduccion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionPedidos
{
    use TransaccionPos;

    /** @param array{modalidad:string,mesaId:string,mozoId:string,comensales:string,observaciones:string,destinatario:string,telefono:string,direccion:string,referencia:string,costoEnvio:string} $datos */
    public function abrir(User $actor, int $turnoId, string $clave, array $datos): Pedido
    {
        return $this->ejecutar($actor, 'pedidos.crear', function () use ($actor, $turnoId, $clave, $datos): Pedido {
            AccesoPos::usuario($actor);
            Validator::make(['clave' => $clave], ['clave' => ['required', 'uuid']])->validate();
            if ($existente = Pedido::where('clave_registro', $clave)->lockForUpdate()->first()) {
                abort_unless($existente->creado_por === $actor->id, 403);

                return $existente;
            }
            $this->turno($turnoId);
            Validator::make($datos, [
                'modalidad' => ['required', Rule::in(['mesa', 'rapida', 'delivery'])],
                'mesaId' => ['nullable', 'required_if:modalidad,mesa', 'integer', Rule::exists('mesas', 'id')],
                'mozoId' => ['required', 'integer', Rule::exists('users', 'id')],
                'comensales' => ['nullable', 'integer', 'min:1', 'max:65535'], 'observaciones' => ['nullable', 'string', 'max:2000'],
                'destinatario' => ['nullable', 'required_if:modalidad,delivery', 'string', 'max:150'],
                'telefono' => ['nullable', 'required_if:modalidad,delivery', 'string', 'max:30'],
                'direccion' => ['nullable', 'required_if:modalidad,delivery', 'string', 'max:250'],
                'referencia' => ['nullable', 'string', 'max:250'],
            ], [], ['mesaId' => 'mesa', 'mozoId' => 'responsable', 'comensales' => 'comensales', 'destinatario' => 'destinatario'])->validate();
            abort_unless((int) $datos['mozoId'] === $actor->id || User::findOrFail($actor->id)->can('pedidos.ver_todos'), 403);
            $mozo = AccesoPos::usuario(User::findOrFail($datos['mozoId']));
            Gate::forUser($mozo)->authorize('pedidos.crear');
            $mesa = $datos['modalidad'] === 'mesa' ? Mesa::with('salon')->lockForUpdate()->findOrFail($datos['mesaId']) : null;
            if ($datos['modalidad'] !== 'mesa' && $datos['mesaId'] !== '') {
                throw ValidationException::withMessages(['mesaId' => 'La venta para llevar y el delivery no ocupan una mesa.']);
            }
            if ($mesa) {
                $mesa->setRelation('salon', Salon::lockForUpdate()->findOrFail($mesa->salon_id));
            }
            if ($mesa && (! $mesa->activo || ! $mesa->salon->activo || OcupacionMesa::where('mesa_id', $mesa->id)->lockForUpdate()->first()
                || Pedido::where('mesa_id', $mesa->id)->whereNotIn('estado', ['cerrado', 'anulado'])->lockForUpdate()->first())) {
                throw ValidationException::withMessages(['mesaId' => 'La mesa está ocupada o no está habilitada. Actualiza el salón.']);
            }
            $envio = $datos['modalidad'] === 'delivery' ? ImportesCompra::importe($datos['costoEnvio'], 'costoEnvio') : '0.00';
            $pedido = Pedido::create(['codigo' => 'PED-'.Str::ulid(), 'clave_registro' => $clave, 'modalidad' => $datos['modalidad'],
                'mesa_id' => $mesa?->id, 'mesa_codigo' => $mesa?->codigo, 'salon_nombre' => $mesa?->salon->nombre,
                'mozo_id' => $mozo->id, 'mozo_nombre' => $mozo->name, 'creado_por' => $actor->id, 'turno_apertura_id' => $turnoId,
                'comensales' => $datos['comensales'] ?: null, 'observaciones' => trim($datos['observaciones']) ?: null, 'estado' => 'abierto', 'abierto_at' => now()]);
            if ($mesa) {
                OcupacionMesa::create(['mesa_id' => $mesa->id, 'pedido_id' => $pedido->id]);
            }
            if ($datos['modalidad'] === 'delivery') {
                $pedido->delivery()->create(['destinatario' => trim($datos['destinatario']), 'telefono' => trim($datos['telefono']), 'direccion' => trim($datos['direccion']),
                    'referencia' => trim($datos['referencia']) ?: null, 'costo_envio' => $envio, 'tipo_transporte' => 'propio', 'estado' => 'pendiente']);
            }
            activity()->causedBy($actor)->performedOn($pedido)->event('pedido_abierto')->withProperties(['turno_id' => $turnoId, 'mesa_id' => $mesa?->id])->log('Atención abierta como borrador');

            return $pedido;
        });
    }

    public static function huella(Pedido $pedido): string
    {
        return hash('sha256', json_encode([$pedido->getAttributes(), $pedido->detalles()->orderBy('id')->get()->toArray()], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{clienteId:string,comensales:string,observaciones:string}  $datos
     * @param  array<string,array{detalleId:string,productoId:string,cantidad:string,nota:string}>  $filas
     */
    public function guardar(User $actor, int $id, int $turnoId, string $version, array $datos, array $filas): Pedido
    {
        return $this->ejecutar($actor, 'pedidos.editar', function () use ($actor, $id, $turnoId, $version, $datos, $filas): Pedido {
            AccesoPos::usuario($actor);
            $this->turno($turnoId);
            Validator::make($datos, ['clienteId' => ['nullable', 'integer'], 'comensales' => ['nullable', 'integer', 'min:1', 'max:65535'], 'observaciones' => ['nullable', 'string', 'max:2000']])->validate();
            $lectura = Pedido::findOrFail($id);
            AccesoPos::pedido($actor, $lectura);
            if ((string) ($lectura->cliente_id ?? '') !== $datos['clienteId']) {
                Gate::forUser(User::findOrFail($actor->id))->authorize('clientes.ver');
            }
            TipoDocumentoIdentidad::orderBy('id')->lockForUpdate()->get();
            $cliente = $datos['clienteId'] !== '' ? Cliente::lockForUpdate()->findOrFail($datos['clienteId']) : null;
            $pedido = Pedido::lockForUpdate()->findOrFail($id);
            $this->editable($actor, $pedido, $version);

            if ((string) ($pedido->cliente_id ?? '') !== $datos['clienteId']) {
                Gate::forUser(User::findOrFail($actor->id))->authorize('clientes.ver');
                if ($cliente && ! $cliente->activo) {
                    throw ValidationException::withMessages(['clienteId' => 'Selecciona un cliente activo.']);
                }
            }
            Validator::make(['filas' => $filas], ['filas' => ['array', 'max:50'], 'filas.*' => ['array:detalleId,productoId,cantidad,nota'],
                'filas.*.detalleId' => ['present', 'nullable', 'integer', 'distinct'], 'filas.*.productoId' => ['required', 'integer'],
                'filas.*.cantidad' => ['required', 'string', 'regex:/^[1-9][0-9]{0,3}$/'], 'filas.*.nota' => ['present', 'nullable', 'string', 'max:500']])->validate();
            $actuales = $pedido->detalles()->whereNull('detalle_padre_id')->where('estado', 'borrador')->lockForUpdate()->get();
            foreach ($actuales as $actual) {
                $this->lineaEditable($actual);
                foreach ($actual->hijos as $hijo) {
                    $this->lineaEditable($hijo);
                }
            }
            $plan = [];
            $necesario = [];
            $vistos = [];
            $total = '0.00';
            foreach ($filas as $fila) {
                $anterior = $fila['detalleId'] !== '' ? $actuales->firstWhere('id', (int) $fila['detalleId']) : null;
                if ($fila['detalleId'] !== '' && (! $anterior || $anterior->producto_id !== (int) $fila['productoId'])) {
                    throw ValidationException::withMessages(['filas' => 'Una línea no pertenece al borrador o cambió de producto. Vuelve a abrirlo.']);
                }
                $producto = Producto::with(['articulo.unidadMedida', 'categoria', 'areaPreparacion', 'componentes.producto.articulo.unidadMedida', 'componentes.producto.areaPreparacion'])->findOrFail($fila['productoId']);
                app(CatalogoPos::class)->comprobar($producto);
                $cantidad = CalculoProduccion::cantidad($fila['cantidad']);
                $precio = $anterior instanceof PedidoDetalle ? $anterior->precio_unitario : $producto->precio_venta;
                $total = ImportesCompra::redondear(bcadd($total, bcmul($cantidad, $precio, 6), 6));
                $componentes = [];
                if ($producto->tipo->value === 'combo') {
                    $origen = $anterior ? $anterior->hijos : $producto->componentes;
                    if ($origen->isEmpty()) {
                        throw ValidationException::withMessages(['filas' => 'El combo no tiene componentes guardados.']);
                    }
                    foreach ($origen as $componente) {
                        $real = $componente->producto;
                        app(CatalogoPos::class)->comprobar($real, false);
                        if ($real->tipo->value === 'combo') {
                            throw ValidationException::withMessages(['filas' => 'No se admiten combos anidados.']);
                        }
                        $factor = CalculoProduccion::cantidad($componente instanceof PedidoDetalle ? ($componente->factor_componente ?? '0') : $componente->cantidad);
                        $base = CalculoProduccion::cantidad(bcmul($cantidad, $factor, 4));
                        $componentes[] = ['producto' => $real, 'factor' => $factor, 'cantidad' => $base, 'descripcion' => $componente instanceof PedidoDetalle ? $componente->descripcion : $real->nombre, 'area' => $componente instanceof PedidoDetalle ? $componente->area_preparacion_id : $real->area_preparacion_id];
                        $articuloId = $real->articulo->id;
                        $necesario[$articuloId] = bcadd($necesario[$articuloId] ?? '0', $base, 4);
                    }
                } else {
                    $articuloId = $producto->articulo->id;
                    $necesario[$articuloId] = bcadd($necesario[$articuloId] ?? '0', $cantidad, 4);
                }
                $plan[] = ['anterior' => $anterior, 'producto' => $producto, 'cantidad' => $cantidad, 'precio' => $precio, 'nota' => trim($fila['nota']), 'componentes' => $componentes];
            }
            foreach ($necesario as $articuloId => $cantidad) {
                $articulo = Articulo::findOrFail($articuloId);
                if (bccomp($cantidad, $articulo->disponible, 4) > 0) {
                    throw ValidationException::withMessages(['filas' => 'No hay suficiente '.$articulo->nombre.' para el conjunto de productos del borrador.']);
                }
            }
            foreach ($plan as $fila) {
                $producto = $fila['producto'];
                $detalle = $fila['anterior'] ?? new PedidoDetalle(['pedido_id' => $pedido->id, 'producto_id' => $producto->id, 'tipo_linea' => 'producto', 'descripcion' => $producto->nombre,
                    'area_preparacion_id' => $producto->tipo->value === 'combo' ? null : $producto->area_preparacion_id, 'precio_unitario' => $fila['precio'], 'estado' => 'borrador']);
                $detalle->fill(['cantidad' => $fila['cantidad'], 'nota' => $fila['nota'] ?: null])->save();
                $vistos[] = $detalle->id;
                $detalle->hijos()->delete();
                foreach ($fila['componentes'] as $componente) {
                    $detalle->hijos()->create(['pedido_id' => $pedido->id, 'producto_id' => $componente['producto']->id, 'tipo_linea' => 'componente', 'descripcion' => $componente['descripcion'],
                        'area_preparacion_id' => $componente['area'], 'cantidad' => $componente['cantidad'], 'factor_componente' => $componente['factor'], 'precio_unitario' => '0', 'estado' => 'borrador', 'nota' => $fila['nota'] ?: null]);
                }
            }
            foreach ($actuales->whereNotIn('id', $vistos) as $omitido) {
                $omitido->hijos()->delete();
                $omitido->delete();
            }
            $pedido->update(['cliente_id' => $cliente?->id, 'comensales' => $datos['comensales'] ?: null, 'observaciones' => trim($datos['observaciones']) ?: null]);
            activity()->causedBy($actor)->performedOn($pedido)->event('pedido_borrador')->withProperties(['turno_id' => $turnoId, 'lineas' => count($filas), 'total_productos' => $total])->log('Borrador guardado sin reservar ni descontar existencias');

            return $pedido;
        });
    }

    public function descartar(User $actor, int $id, string $version, string $motivo): void
    {
        $this->ejecutar($actor, 'pedidos.editar', function () use ($actor, $id, $version, $motivo): void {
            $pedido = Pedido::lockForUpdate()->findOrFail($id);
            AccesoPos::pedido($actor, $pedido);
            if ($pedido->estado->value === 'anulado') {
                return;
            }
            $this->editable($actor, $pedido, $version);
            if ($pedido->mesa_id) {
                Gate::forUser(User::findOrFail($actor->id))->authorize('mesas.liberar');
            }
            if ($pedido->cobros()->exists() || $pedido->detalles()->where('estado', '<>', 'borrador')->exists()) {
                throw ValidationException::withMessages(['seguridad' => 'Solo se descartan borradores sin productos confirmados ni cobros.']);
            }
            Validator::make(['motivo' => trim($motivo)], ['motivo' => ['required', 'string', 'min:5', 'max:500']])->validate();
            foreach ($pedido->detalles as $detalle) {
                $this->lineaEditable($detalle);
                $detalle->update(['estado' => 'anulado', 'cantidad_anulada' => $detalle->cantidad]);
            }
            $pedido->update(['estado' => 'anulado', 'motivo_anulacion' => trim($motivo), 'anulado_por' => $actor->id, 'cerrado_at' => now()]);
            if ($pedido->delivery) {
                $pedido->delivery->update(['estado' => 'cancelado']);
            }
            OcupacionMesa::where('pedido_id', $pedido->id)->delete();
            activity()->causedBy($actor)->performedOn($pedido)->event('pedido_descartado')->withProperties(['motivo' => trim($motivo)])->log('Borrador descartado y mesa liberada sin movimientos');
        });
    }

    private function editable(User $actor, Pedido $pedido, string $version): void
    {
        AccesoPos::pedido($actor, $pedido);
        if (in_array($pedido->estado->value, ['cerrado', 'anulado'], true) || ! hash_equals(self::huella($pedido), $version)) {
            throw ValidationException::withMessages(['seguridad' => 'El pedido cambió o ya terminó. Vuelve a abrirlo antes de guardar.']);
        }
    }

    private function lineaEditable(PedidoDetalle $detalle): void
    {
        if ($detalle->estado->value !== 'borrador' || $detalle->confirmacion_pedido_id || $detalle->confirmado_at || bccomp($detalle->cantidad_anulada, '0', 4) > 0 || bccomp($detalle->cantidad_cobrada, '0', 4) > 0 || bccomp($detalle->cantidad_entregada, '0', 4) > 0
            || ReservaInventario::where('pedido_detalle_id', $detalle->id)->exists() || EnvioCocinaDetalle::where('pedido_detalle_id', $detalle->id)->exists() || CobroDetalle::where('pedido_detalle_id', $detalle->id)->exists()) {
            throw ValidationException::withMessages(['seguridad' => 'Una línea ya participa en una operación confirmada y no puede editarse como borrador.']);
        }
    }
}
