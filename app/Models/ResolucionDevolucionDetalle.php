<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $resolucion_devolucion_id
 * @property int $pedido_detalle_id
 * @property int $articulo_id
 * @property int|null $movimiento_entrega_id
 * @property string $descripcion
 * @property string $destino
 * @property numeric-string $cantidad
 * @property numeric-string $costo_unitario
 * @property array<int,mixed>|null $avance_antes
 * @property array<int,mixed>|null $avance_despues
 */
class ResolucionDevolucionDetalle extends Model
{
    protected $table = 'resolucion_devolucion_detalles';

    protected $fillable = ['resolucion_devolucion_id', 'pedido_detalle_id', 'articulo_id', 'movimiento_entrega_id', 'descripcion', 'destino', 'cantidad', 'costo_unitario', 'avance_antes', 'avance_despues'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['cantidad' => 'decimal:4', 'costo_unitario' => 'decimal:6', 'avance_antes' => 'array', 'avance_despues' => 'array'];
    }
}
