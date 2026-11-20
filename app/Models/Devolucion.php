<?php

namespace App\Models;

use App\Enums\EstadoDevolucion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property numeric-string $cargo_envio
 * @property string|null $usuario_nombre
 * @property string|null $caja_nombre
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $codigo
 * @property int $cobro_id
 * @property int $turno_caja_id
 * @property int $usuario_id
 * @property int $autorizado_por
 * @property string $motivo
 * @property EstadoDevolucion $estado
 * @property numeric-string $total
 * @property CarbonImmutable|null $devuelta_at
 * @property string $clave_idempotencia
 */
class Devolucion extends Model
{
    use LogsActivity;

    protected $table = 'devoluciones';

    protected $fillable = [
        'caja_nombre',
        'usuario_nombre',
        'cargo_envio',
        'codigo',
        'cobro_id',
        'turno_caja_id',
        'usuario_id',
        'autorizado_por',
        'motivo',
        'estado',
        'total',
        'devuelta_at',
        'clave_idempotencia',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cargo_envio' => 'decimal:2',
            'estado' => EstadoDevolucion::class,
            'total' => 'decimal:2',
            'devuelta_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Cobro, $this> */
    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class, 'cobro_id');
    }

    /** @return BelongsTo<TurnoCaja, $this> */
    public function turnoCaja(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id');
    }

    /** @return HasMany<DevolucionDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(DevolucionDetalle::class, 'devolucion_id');
    }

    /** @return HasMany<DevolucionPago, $this> */
    public function pagos(): HasMany
    {
        return $this->hasMany(DevolucionPago::class, 'devolucion_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'cobro_id', 'turno_caja_id', 'usuario_id', 'autorizado_por', 'motivo', 'estado', 'total', 'devuelta_at', 'clave_idempotencia'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
