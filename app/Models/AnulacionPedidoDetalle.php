<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $descripcion
 * @property numeric-string $cantidad_liberada
 * @property numeric-string $cantidad_merma
 */
class AnulacionPedidoDetalle extends Model
{
    protected $table = 'anulacion_pedido_detalles';

    protected $fillable = ['anulacion_pedido_id', 'pedido_detalle_id', 'articulo_id', 'descripcion', 'cantidad_liberada', 'cantidad_merma', 'avance_antes', 'avance_despues'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['cantidad_liberada' => 'decimal:4', 'cantidad_merma' => 'decimal:4', 'avance_antes' => 'array', 'avance_despues' => 'array'];
    }
}
