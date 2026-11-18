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
 * @property string $codigo
 * @property string $nombre
 * @property int|null $impresora_id
 * @property bool $activo
 */
class Caja extends Model
{
    protected $table = 'cajas';

    protected $fillable = [
        'codigo',
        'nombre',
        'impresora_id',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<TurnoCaja, $this> */
    public function turnos(): HasMany
    {
        return $this->hasMany(TurnoCaja::class, 'caja_id');
    }

    /** @return BelongsTo<Impresora, $this> */
    public function impresora(): BelongsTo
    {
        return $this->belongsTo(Impresora::class, 'impresora_id');
    }
}
