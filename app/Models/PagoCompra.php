<?php

namespace App\Models;

use App\Enums\OrigenFondosPagoCompra;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $compra_id
 * @property int $medio_pago_id
 * @property int|null $turno_caja_id
 * @property int $usuario_id
 * @property OrigenFondosPagoCompra $origen_fondos
 * @property numeric-string $monto
 * @property CarbonImmutable $pagado_at
 * @property string|null $referencia
 * @property string $clave_idempotencia
 * @property string|null $observaciones
 */
class PagoCompra extends Model
{
    protected $table = 'pagos_compra';

    protected $fillable = [
        'compra_id',
        'medio_pago_id',
        'turno_caja_id',
        'usuario_id',
        'origen_fondos',
        'monto',
        'pagado_at',
        'referencia',
        'clave_idempotencia',
        'observaciones',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'origen_fondos' => OrigenFondosPagoCompra::class,
            'monto' => 'decimal:2',
            'pagado_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Compra, $this> */
    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    /** @return BelongsTo<MedioPago, $this> */
    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class, 'medio_pago_id');
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
