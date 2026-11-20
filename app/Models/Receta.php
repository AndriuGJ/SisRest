<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $producto_id
 * @property numeric-string $rendimiento
 * @property string|null $observaciones
 * @property bool $activo
 */
class Receta extends Model
{
    protected $table = 'recetas';

    protected $fillable = [
        'producto_id',
        'rendimiento',
        'observaciones',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rendimiento' => 'decimal:4',
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /** @return HasMany<RecetaDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(RecetaDetalle::class, 'receta_id');
    }
}
