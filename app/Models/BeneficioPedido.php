<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $usuario_nombre
 * @property string $accion
 * @property array<string,mixed> $antes
 * @property int $id
 * @property int $pedido_id
 * @property int $pedido_detalle_id
 * @property int $usuario_id
 */
class BeneficioPedido extends Model
{
    protected $table = 'beneficios_pedido';

    protected $fillable = ['usuario_nombre', 'clave_idempotencia', 'pedido_id', 'pedido_detalle_id', 'usuario_id', 'accion', 'motivo', 'antes', 'despues', 'autorizado_at'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array', 'autorizado_at' => 'immutable_datetime'];
    }
}
