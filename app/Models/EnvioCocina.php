<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int|null $confirmacion_pedido_id
 * @property string|null $area_nombre
 * @property int $pedido_id
 * @property int $area_preparacion_id
 * @property int $usuario_id
 * @property int $numero_envio
 * @property CarbonImmutable|null $terminado_at
 * @property int|null $terminado_por
 * @property CarbonImmutable $enviado_at
 */
class EnvioCocina extends Model
{
    protected $table = 'envios_cocina';

    protected $fillable = [
        'confirmacion_pedido_id', 'area_nombre',
        'pedido_id',
        'area_preparacion_id',
        'usuario_id',
        'numero_envio',
        'enviado_at', 'terminado_at', 'terminado_por',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enviado_at' => 'immutable_datetime',
            'terminado_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Pedido, $this> */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    /** @return BelongsTo<AreaPreparacion, $this> */
    public function areaPreparacion(): BelongsTo
    {
        return $this->belongsTo(AreaPreparacion::class, 'area_preparacion_id');
    }

    /** @return HasMany<EnvioCocinaDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(EnvioCocinaDetalle::class, 'envio_cocina_id');
    }
}
