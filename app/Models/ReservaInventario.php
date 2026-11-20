<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $pedido_detalle_id
 * @property int $articulo_id
 * @property numeric-string $cantidad
 * @property numeric-string $cantidad_consumida
 * @property numeric-string $cantidad_liberada
 * @property string $clave_idempotencia
 */
class ReservaInventario extends Model
{
    protected $table = 'reservas_inventario';

    protected $fillable = [
        'pedido_detalle_id',
        'articulo_id',
        'cantidad',
        'cantidad_consumida',
        'cantidad_liberada',
        'clave_idempotencia',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
            'cantidad_consumida' => 'decimal:4',
            'cantidad_liberada' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PedidoDetalle, $this> */
    public function detallePedido(): BelongsTo
    {
        return $this->belongsTo(PedidoDetalle::class, 'pedido_detalle_id');
    }

    /** @return BelongsTo<Articulo, $this> */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }
}
