<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $combo_id
 * @property int $producto_id
 * @property numeric-string $cantidad
 */
class ComboComponente extends Model
{
    protected $table = 'combo_componentes';

    protected $fillable = [
        'combo_id',
        'producto_id',
        'cantidad',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Producto, $this> */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'combo_id');
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
