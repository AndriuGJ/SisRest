<?php

namespace App\Models;

use App\Enums\DestinoProductoDevolucionDetalle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property numeric-string $cantidad_resuelta
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $devolucion_id
 * @property int $cobro_detalle_id
 * @property numeric-string $cantidad
 * @property numeric-string $importe
 * @property DestinoProductoDevolucionDetalle $destino_producto
 */
class DevolucionDetalle extends Model
{
    protected $table = 'devolucion_detalles';

    protected $fillable = [
        'cantidad_resuelta',
        'devolucion_id',
        'cobro_detalle_id',
        'cantidad',
        'importe',
        'destino_producto',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad_resuelta' => 'decimal:4',
            'cantidad' => 'decimal:4',
            'importe' => 'decimal:2',
            'destino_producto' => DestinoProductoDevolucionDetalle::class,
        ];
    }

    /** @return BelongsTo<Devolucion, $this> */
    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(Devolucion::class, 'devolucion_id');
    }

    /** @return BelongsTo<CobroDetalle, $this> */
    public function detalleCobro(): BelongsTo
    {
        return $this->belongsTo(CobroDetalle::class, 'cobro_detalle_id');
    }

    /** @return HasMany<ResolucionDevolucion, $this> */
    public function resoluciones(): HasMany
    {
        return $this->hasMany(ResolucionDevolucion::class, 'devolucion_detalle_id');
    }
}
