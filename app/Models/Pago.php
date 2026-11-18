<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $cobro_id
 * @property int $medio_pago_id
 * @property numeric-string $monto
 * @property numeric-string $monto_entregado
 * @property numeric-string $vuelto
 * @property string|null $referencia
 * @property CarbonImmutable $recibido_at
 * @property string $clave_idempotencia
 */
class Pago extends Model
{
    protected $table = 'pagos';

    protected $fillable = [
        'cobro_id',
        'medio_pago_id',
        'monto',
        'monto_entregado',
        'vuelto',
        'referencia',
        'recibido_at',
        'clave_idempotencia',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'monto_entregado' => 'decimal:2',
            'vuelto' => 'decimal:2',
            'recibido_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Cobro, $this> */
    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class, 'cobro_id');
    }

    /** @return BelongsTo<MedioPago, $this> */
    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class, 'medio_pago_id');
    }
}
