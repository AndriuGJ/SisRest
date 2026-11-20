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
 * @property int $orden
 * @property bool $activo
 */
class Salon extends Model
{
    protected $table = 'salones';

    protected $fillable = [
        'codigo',
        'nombre',
        'orden',
        'activo',
    ];

    /** @return HasMany<Mesa, $this> */
    public function mesas(): HasMany
    {
        return $this->hasMany(Mesa::class, 'salon_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }
}
