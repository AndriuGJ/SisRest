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
 * @property string|null $clave_registro
 * @property string|null $receta_huella
 * @property numeric-string|null $rendimiento_receta
 * @property string|null $producto_nombre
 * @property string|null $unidad_codigo
 * @property string|null $motivo_ajuste
 * @property int|null $confirmada_por
 * @property int|null $anulada_por
 * @property CarbonImmutable|null $anulada_at
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
        'clave_registro', 'receta_huella', 'rendimiento_receta', 'producto_nombre', 'unidad_codigo',
        'motivo_ajuste', 'confirmada_por', 'anulada_por', 'anulada_at',
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
            'rendimiento_receta' => 'decimal:4', 'anulada_at' => 'immutable_datetime',
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

    /** @return BelongsTo<User, $this> */
    public function confirmadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmada_por');
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
