<?php

namespace App\Models;

use App\Enums\EstadoPreparacion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $codigo
 * @property int $producto_id
 * @property int|null $receta_id
 * @property int $usuario_id
 * @property CarbonImmutable $fecha
 * @property EstadoPreparacion $estado
 * @property numeric-string $cantidad_producida
 * @property numeric-string $costo_total
 * @property CarbonImmutable|null $confirmada_at
 * @property string|null $observaciones
 * @property string|null $motivo_anulacion
 */
class Preparacion extends Model
{
    use LogsActivity;

    protected $table = 'preparaciones';

    protected $fillable = [
        'codigo',
        'producto_id',
        'receta_id',
        'usuario_id',
        'fecha',
        'estado',
        'cantidad_producida',
        'costo_total',
        'confirmada_at',
        'observaciones',
        'motivo_anulacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha' => 'immutable_date',
            'estado' => EstadoPreparacion::class,
            'cantidad_producida' => 'decimal:4',
            'costo_total' => 'decimal:6',
            'confirmada_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /** @return BelongsTo<Receta, $this> */
    public function receta(): BelongsTo
    {
        return $this->belongsTo(Receta::class, 'receta_id');
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** @return HasMany<PreparacionDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(PreparacionDetalle::class, 'preparacion_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'producto_id', 'receta_id', 'usuario_id', 'fecha', 'estado', 'cantidad_producida', 'costo_total', 'confirmada_at', 'observaciones', 'motivo_anulacion'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
