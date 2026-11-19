<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $medio_pago_nombre
 * @property bool|null $afecta_efectivo
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $devolucion_id
 * @property int|null $pago_original_id
 * @property int $medio_pago_id
 * @property numeric-string $monto
 * @property string|null $referencia
 * @property string $clave_idempotencia
 */
class DevolucionPago extends Model
{
    protected $table = 'devolucion_pagos';

    protected $fillable = [
        'afecta_efectivo',
        'medio_pago_nombre',
        'devolucion_id',
        'pago_original_id',
        'medio_pago_id',
        'monto',
        'referencia',
        'clave_idempotencia',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'afecta_efectivo' => 'boolean',
            'monto' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Devolucion, $this> */
    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(Devolucion::class, 'devolucion_id');
    }

    /** @return BelongsTo<Pago, $this> */
    public function pagoOriginal(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_original_id');
    }

    /** @return BelongsTo<MedioPago, $this> */
    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class, 'medio_pago_id');
    }
}
