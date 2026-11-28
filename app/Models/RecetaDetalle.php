<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $receta_id
 * @property int $articulo_id
 * @property numeric-string $cantidad
 */
class RecetaDetalle extends Model
{
    protected $table = 'receta_detalles';

    protected $fillable = [
        'receta_id',
        'articulo_id',
        'cantidad',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Receta, $this> */
    public function receta(): BelongsTo
    {
        return $this->belongsTo(Receta::class, 'receta_id');
    }

    /** @return BelongsTo<Articulo, $this> */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }
}
