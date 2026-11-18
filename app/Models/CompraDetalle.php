<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $compra_id
 * @property int $articulo_id
 * @property int|null $presentacion_articulo_id
 * @property string $descripcion
 * @property numeric-string $cantidad
 * @property numeric-string $factor_conversion
 * @property numeric-string $cantidad_base
 * @property numeric-string $costo_unitario
 * @property numeric-string $descuento
 * @property numeric-string $impuestos
 * @property numeric-string $total
 */
class CompraDetalle extends Model
{
    protected $table = 'compra_detalles';

    protected $fillable = [
        'compra_id',
        'articulo_id',
        'presentacion_articulo_id',
        'descripcion',
        'cantidad',
        'factor_conversion',
        'cantidad_base',
        'costo_unitario',
        'descuento',
        'impuestos',
        'total',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
            'factor_conversion' => 'decimal:4',
            'cantidad_base' => 'decimal:4',
            'costo_unitario' => 'decimal:6',
            'descuento' => 'decimal:2',
            'impuestos' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Compra, $this> */
    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    /** @return BelongsTo<Articulo, $this> */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }

    /** @return BelongsTo<PresentacionArticulo, $this> */
    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(PresentacionArticulo::class, 'presentacion_articulo_id');
    }
}
