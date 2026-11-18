<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string|null $descripcion
 * @property string|null $unidad_codigo
 * @property int $preparacion_id
 * @property int $articulo_id
 * @property numeric-string $cantidad_estandar
 * @property numeric-string $cantidad_utilizada
 * @property numeric-string $costo_unitario
 * @property numeric-string $costo_total
 */
class PreparacionDetalle extends Model
{
    protected $table = 'preparacion_detalles';

    protected $fillable = [
        'descripcion', 'unidad_codigo',
        'preparacion_id',
        'articulo_id',
        'cantidad_estandar',
        'cantidad_utilizada',
        'costo_unitario',
        'costo_total',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad_estandar' => 'decimal:4',
            'cantidad_utilizada' => 'decimal:4',
            'costo_unitario' => 'decimal:6',
            'costo_total' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<Preparacion, $this> */
    public function preparacion(): BelongsTo
    {
        return $this->belongsTo(Preparacion::class, 'preparacion_id');
    }

    /** @return BelongsTo<Articulo, $this> */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }
}
