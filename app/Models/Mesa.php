<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $salon_id
 * @property string $codigo
 * @property int|null $capacidad
 * @property bool $activo
 */
class Mesa extends Model
{
    protected $table = 'mesas';

    protected $fillable = [
        'salon_id',
        'codigo',
        'capacidad',
        'activo',
    ];

    /** @return HasOne<OcupacionMesa, $this> */
    public function ocupacion(): HasOne
    {
        return $this->hasOne(OcupacionMesa::class, 'mesa_id');
    }

    /** @return HasMany<Pedido, $this> */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'mesa_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Salon, $this> */
    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class, 'salon_id');
    }
}
