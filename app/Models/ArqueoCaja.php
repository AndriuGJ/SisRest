<?php

namespace App\Models;

use App\Enums\TipoArqueoCaja;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $turno_caja_id
 * @property int $usuario_id
 * @property TipoArqueoCaja $tipo
 * @property int|null $cierre_turno_id
 * @property numeric-string $efectivo_esperado
 * @property numeric-string $efectivo_contado
 * @property numeric-string $diferencia
 * @property CarbonImmutable $contado_at
 * @property string|null $observaciones
 */
class ArqueoCaja extends Model
{
    protected $table = 'arqueos_caja';

    protected $fillable = [
        'turno_caja_id',
        'usuario_id',
        'tipo',
        'efectivo_esperado',
        'efectivo_contado',
        'diferencia',
        'contado_at',
        'observaciones',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoArqueoCaja::class,
            'efectivo_esperado' => 'decimal:2',
            'efectivo_contado' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'contado_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TurnoCaja, $this> */
    public function turnoCaja(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id');
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
