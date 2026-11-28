<?php

namespace App\Models;

use App\Enums\TipoArticulo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property string $nombre
 * @property TipoArticulo $tipo
 * @property int $unidad_medida_id
 * @property numeric-string $stock_actual
 * @property numeric-string $stock_reservado
 * @property numeric-string $stock_minimo
 * @property numeric-string $costo_promedio
 * @property bool $activo
 * @property numeric-string $disponible
 */
class Articulo extends Model
{
    use LogsActivity;

    protected $table = 'articulos';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'unidad_medida_id',
        'stock_actual',
        'stock_reservado',
        'stock_minimo',
        'costo_promedio',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoArticulo::class,
            'stock_actual' => 'decimal:4',
            'stock_reservado' => 'decimal:4',
            'stock_minimo' => 'decimal:4',
            'costo_promedio' => 'decimal:6',
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<UnidadMedida, $this> */
    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_medida_id');
    }

    /** @return HasMany<PresentacionArticulo, $this> */
    public function presentaciones(): HasMany
    {
        return $this->hasMany(PresentacionArticulo::class, 'articulo_id');
    }

    /** @return Attribute<numeric-string, never> */
    protected function disponible(): Attribute
    {
        return Attribute::get(fn (): string => bcsub($this->stock_actual, $this->stock_reservado, 4));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'nombre', 'tipo', 'unidad_medida_id', 'stock_minimo', 'activo'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
