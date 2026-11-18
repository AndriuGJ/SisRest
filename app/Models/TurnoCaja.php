<?php

namespace App\Models;

use App\Enums\EstadoTurnoCaja;
use App\Enums\TipoMovimientoCaja;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $caja_id
 * @property int $usuario_id
 * @property EstadoTurnoCaja $estado
 * @property int|null $caja_abierta_id
 * @property numeric-string $fondo_inicial
 * @property CarbonImmutable $abierta_at
 * @property CarbonImmutable|null $cerrada_at
 * @property int|null $cerrada_por
 * @property string|null $observaciones
 */
class TurnoCaja extends Model
{
    protected $table = 'turnos_caja';

    protected $fillable = [
        'caja_id',
        'usuario_id',
        'estado',
        'fondo_inicial',
        'abierta_at',
        'cerrada_at',
        'cerrada_por',
        'observaciones',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'estado' => EstadoTurnoCaja::class,
            'fondo_inicial' => 'decimal:2',
            'abierta_at' => 'immutable_datetime',
            'cerrada_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Caja, $this> */
    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** @return HasMany<MovimientoCaja, $this> */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class, 'turno_caja_id');
    }

    /** @return HasMany<ArqueoCaja, $this> */
    public function arqueos(): HasMany
    {
        return $this->hasMany(ArqueoCaja::class, 'turno_caja_id');
    }

    /** @return numeric-string */
    public function efectivoEsperado(): string
    {
        $saldo = $this->fondo_inicial;

        foreach ($this->movimientos()->cursor() as $movimiento) {
            $saldo = $movimiento->tipo === TipoMovimientoCaja::INGRESO
                ? bcadd($saldo, $movimiento->monto, 2)
                : bcsub($saldo, $movimiento->monto, 2);
        }

        return $saldo;
    }
}
