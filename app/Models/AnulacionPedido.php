<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $usuario_nombre
 * @property int $id
 * @property int $pedido_id
 * @property int $pedido_detalle_id
 * @property int $usuario_id
 * @property string $motivo
 * @property numeric-string $cantidad
 */
class AnulacionPedido extends Model
{
    protected $table = 'anulaciones_pedido';

    protected $fillable = ['usuario_nombre', 'clave_idempotencia', 'pedido_id', 'pedido_detalle_id', 'usuario_id', 'cantidad', 'motivo', 'anulado_at'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['cantidad' => 'decimal:4', 'anulado_at' => 'immutable_datetime'];
    }

    /** @return HasMany<AnulacionPedidoDetalle,$this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(AnulacionPedidoDetalle::class, 'anulacion_pedido_id');
    }
}
