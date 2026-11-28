<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $codigo
 * @property string $nombre
 * @property bool $activo
 */
class AreaPreparacion extends Model
{
    protected $table = 'areas_preparacion';

    protected $fillable = [
        'codigo',
        'nombre',
        'activo',
    ];

    /** @return HasMany<Impresora, $this> */
    public function impresoras(): HasMany
    {
        return $this->hasMany(Impresora::class, 'area_preparacion_id');
    }

    /** @return HasMany<Producto, $this> */
    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'area_preparacion_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }
}
