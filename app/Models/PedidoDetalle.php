<?php

namespace App\Models;

use App\Enums\EstadoPedidoDetalle;
use App\Enums\TipoLineaPedidoDetalle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $pedido_id
 * @property int $producto_id
 * @property int|null $detalle_padre_id
 * @property TipoLineaPedidoDetalle $tipo_linea
 * @property string $descripcion
 * @property int|null $area_preparacion_id
 * @property numeric-string|null $factor_componente
 * @property numeric-string $cantidad
 * @property numeric-string $cantidad_anulada
 * @property numeric-string $cantidad_entregada
 * @property numeric-string $cantidad_cobrada
 * @property numeric-string $precio_unitario
 * @property EstadoPedidoDetalle $estado
 * @property string|null $nota
 * @property bool $es_cortesia
 * @property int|null $motivo_beneficio_id
 * @property int|null $autorizado_por
 * @property string|null $descuento_tipo
 * @property numeric-string $descuento_valor
 * @property numeric-string $descuento_importe
 * @property CarbonImmutable|null $confirmado_at
 */
class PedidoDetalle extends Model
{
    protected $table = 'pedido_detalles';

    protected $fillable = [
        'factor_componente',
        'pedido_id',
        'producto_id',
        'detalle_padre_id',
        'tipo_linea',
        'descripcion',
        'area_preparacion_id',
        'cantidad',
        'cantidad_anulada',
        'cantidad_entregada',
        'cantidad_cobrada',
        'precio_unitario',
        'estado',
        'nota',
        'es_cortesia',
        'motivo_beneficio_id',
        'autorizado_por',
        'descuento_tipo',
        'descuento_valor',
        'descuento_importe',
        'confirmado_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo_linea' => TipoLineaPedidoDetalle::class,
            'factor_componente' => 'decimal:4',
            'cantidad' => 'decimal:4',
            'cantidad_anulada' => 'decimal:4',
            'cantidad_entregada' => 'decimal:4',
            'cantidad_cobrada' => 'decimal:4',
            'precio_unitario' => 'decimal:2',
            'estado' => EstadoPedidoDetalle::class,
            'es_cortesia' => 'boolean',
            'descuento_valor' => 'decimal:4',
            'descuento_importe' => 'decimal:2',
            'confirmado_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Pedido, $this> */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    /** @return BelongsTo<Producto, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /** @return BelongsTo<PedidoDetalle, $this> */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(PedidoDetalle::class, 'detalle_padre_id');
    }

    /** @return HasMany<PedidoDetalle, $this> */
    public function hijos(): HasMany
    {
        return $this->hasMany(PedidoDetalle::class, 'detalle_padre_id');
    }

    /** @return HasMany<ReservaInventario, $this> */
    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaInventario::class, 'pedido_detalle_id');
    }
}
