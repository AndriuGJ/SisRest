<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $envio_cocina_id
 * @property int $pedido_detalle_id
 * @property string $descripcion
 * @property string|null $nota
 * @property numeric-string $cantidad
 * @property numeric-string $cantidad_iniciada
 * @property numeric-string $cantidad_lista
 * @property numeric-string $cantidad_entregada
 * @property numeric-string $cantidad_anulada
 */
class EnvioCocinaDetalle extends Model
{
    protected $table = 'envio_cocina_detalles';

    protected $fillable = [
        'envio_cocina_id',
        'pedido_detalle_id',
        'descripcion',
        'nota',
        'cantidad',
        'cantidad_iniciada',
        'cantidad_lista',
        'cantidad_entregada',
        'cantidad_anulada',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
            'cantidad_iniciada' => 'decimal:4',
            'cantidad_lista' => 'decimal:4',
            'cantidad_entregada' => 'decimal:4',
            'cantidad_anulada' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<EnvioCocina, $this> */
    public function envio(): BelongsTo
    {
        return $this->belongsTo(EnvioCocina::class, 'envio_cocina_id');
    }

    /** @return BelongsTo<PedidoDetalle, $this> */
    public function detallePedido(): BelongsTo
    {
        return $this->belongsTo(PedidoDetalle::class, 'pedido_detalle_id');
    }
}
