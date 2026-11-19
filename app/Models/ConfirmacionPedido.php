<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $clave_idempotencia
 * @property int $pedido_id
 * @property int $turno_caja_id
 * @property int $usuario_id
 * @property int $numero_lote
 * @property CarbonImmutable $confirmado_at
 */
class ConfirmacionPedido extends Model
{
    protected $table = 'confirmaciones_pedido';

    protected $fillable = ['clave_idempotencia', 'pedido_id', 'turno_caja_id', 'usuario_id', 'numero_lote', 'confirmado_at'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['confirmado_at' => 'immutable_datetime'];
    }

    /** @return HasMany<PedidoDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(PedidoDetalle::class, 'confirmacion_pedido_id');
    }
}
