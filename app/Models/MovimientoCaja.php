<?php

namespace App\Models;

use App\Enums\TipoMovimientoCaja;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $turno_caja_id
 * @property int $usuario_id
 * @property TipoMovimientoCaja $tipo
 * @property string $concepto
 * @property numeric-string $monto
 * @property string|null $motivo
 * @property string $clave_idempotencia
 * @property CarbonImmutable $registrado_at
 * @property string|null $origen_type
 * @property int|null $origen_id
 */
class MovimientoCaja extends Model
{
    protected $table = 'movimientos_caja';

    protected $fillable = [
        'turno_caja_id',
        'usuario_id',
        'tipo',
        'concepto',
        'monto',
        'motivo',
        'clave_idempotencia',
        'registrado_at',
        'origen_type',
        'origen_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoCaja::class,
            'monto' => 'decimal:2',
            'registrado_at' => 'immutable_datetime',
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

    /** @return MorphTo<Model, $this> */
    public function origen(): MorphTo
    {
        return $this->morphTo();
    }
}
