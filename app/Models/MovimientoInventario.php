<?php

namespace App\Models;

use App\Enums\TipoMovimientoInventario;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $articulo_id
 * @property int $usuario_id
 * @property TipoMovimientoInventario $tipo
 * @property numeric-string $cantidad
 * @property numeric-string $saldo_anterior
 * @property numeric-string $saldo_posterior
 * @property numeric-string $costo_unitario
 * @property string|null $motivo
 * @property string $clave_idempotencia
 * @property CarbonImmutable $registrado_at
 * @property string|null $origen_type
 * @property int|null $origen_id
 */
class MovimientoInventario extends Model
{
    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'articulo_id',
        'usuario_id',
        'tipo',
        'cantidad',
        'saldo_anterior',
        'saldo_posterior',
        'costo_unitario',
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
            'tipo' => TipoMovimientoInventario::class,
            'cantidad' => 'decimal:4',
            'saldo_anterior' => 'decimal:4',
            'saldo_posterior' => 'decimal:4',
            'costo_unitario' => 'decimal:6',
            'registrado_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Articulo, $this> */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
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
