<?php

namespace App\Models;

use App\Enums\TipoProducto;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property TipoProducto $tipo
 * @property int|null $categoria_producto_id
 * @property int|null $articulo_id
 * @property int|null $area_preparacion_id
 * @property numeric-string $precio_venta
 * @property string|null $imagen_url
 * @property string|null $imagen_ruta
 * @property bool $visible_pos
 * @property bool $activo
 */
class Producto extends Model
{
    use LogsActivity;

    protected $table = 'productos';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'tipo',
        'categoria_producto_id',
        'articulo_id',
        'area_preparacion_id',
        'precio_venta',
        'imagen_ruta',
        'visible_pos',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoProducto::class,
            'precio_venta' => 'decimal:2',
            'visible_pos' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function getImagenUrlAttribute(): ?string
    {
        return $this->imagen_ruta ? route('productos.imagen', ['producto' => $this->id, 'v' => substr(hash('sha256', $this->imagen_ruta), 0, 16)], false) : null;
    }

    /** @return BelongsTo<Articulo, $this> */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }

    /** @return BelongsTo<CategoriaProducto, $this> */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_producto_id');
    }

    /** @return BelongsTo<AreaPreparacion, $this> */
    public function areaPreparacion(): BelongsTo
    {
        return $this->belongsTo(AreaPreparacion::class, 'area_preparacion_id');
    }

    /** @return HasOne<Receta, $this> */
    public function receta(): HasOne
    {
        return $this->hasOne(Receta::class, 'producto_id');
    }

    /** @return HasMany<ComboComponente, $this> */
    public function componentes(): HasMany
    {
        return $this->hasMany(ComboComponente::class, 'combo_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'nombre', 'descripcion', 'tipo', 'categoria_producto_id', 'articulo_id', 'area_preparacion_id', 'precio_venta', 'imagen_ruta', 'visible_pos', 'activo'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
