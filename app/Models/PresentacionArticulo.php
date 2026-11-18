<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $articulo_id
 * @property string $nombre
 * @property numeric-string $factor_conversion
 * @property bool $activo
 */
class PresentacionArticulo extends Model
{
    protected $table = 'presentaciones_articulo';

    protected $fillable = [
        'articulo_id',
        'nombre',
        'factor_conversion',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'factor_conversion' => 'decimal:4',
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Articulo, $this> */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }
}
