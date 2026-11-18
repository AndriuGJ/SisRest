<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $cobro_id
 * @property int $pedido_detalle_id
 * @property string $descripcion
 * @property numeric-string $cantidad
 * @property numeric-string $precio_unitario
 * @property numeric-string $subtotal
 * @property numeric-string $descuento
 * @property numeric-string $impuestos
 * @property numeric-string $total
 * @property bool $es_cortesia
 */
class CobroDetalle extends Model
{
    protected $table = 'cobro_detalles';

    protected $fillable = [
        'cobro_id',
        'pedido_detalle_id',
        'descripcion',
        'cantidad',
        'precio_unitario',
        'subtotal',
        'descuento',
        'impuestos',
        'total',
        'es_cortesia',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
            'precio_unitario' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'impuestos' => 'decimal:2',
            'total' => 'decimal:2',
            'es_cortesia' => 'boolean',
        ];
    }

    /** @return BelongsTo<Cobro, $this> */
    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class, 'cobro_id');
    }

    /** @return BelongsTo<PedidoDetalle, $this> */
    public function detallePedido(): BelongsTo
    {
        return $this->belongsTo(PedidoDetalle::class, 'pedido_detalle_id');
    }
}
