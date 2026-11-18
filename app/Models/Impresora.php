<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $codigo
 * @property string $nombre
 * @property string|null $host
 * @property int $puerto
 * @property int $ancho_mm
 * @property int|null $area_preparacion_id
 * @property bool $activo
 */
class Impresora extends Model
{
    protected $table = 'impresoras';

    protected $fillable = [
        'codigo',
        'nombre',
        'host',
        'puerto',
        'ancho_mm',
        'area_preparacion_id',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<AreaPreparacion, $this> */
    public function areaPreparacion(): BelongsTo
    {
        return $this->belongsTo(AreaPreparacion::class, 'area_preparacion_id');
    }
}
