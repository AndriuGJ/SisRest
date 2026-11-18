<?php

namespace App\Models;

use App\Enums\EstadoEntregaDelivery;
use App\Enums\TipoTransporteEntregaDelivery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $pedido_id
 * @property string $destinatario
 * @property string $telefono
 * @property string $direccion
 * @property string|null $referencia
 * @property TipoTransporteEntregaDelivery $tipo_transporte
 * @property string|null $repartidor_nombre
 * @property string|null $repartidor_telefono
 * @property numeric-string $costo_envio
 * @property EstadoEntregaDelivery $estado
 * @property CarbonImmutable|null $salida_at
 * @property CarbonImmutable|null $entregada_at
 */
class EntregaDelivery extends Model
{
    protected $table = 'entregas_delivery';

    protected $fillable = [
        'pedido_id',
        'destinatario',
        'telefono',
        'direccion',
        'referencia',
        'tipo_transporte',
        'repartidor_nombre',
        'repartidor_telefono',
        'costo_envio',
        'estado',
        'salida_at',
        'entregada_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo_transporte' => TipoTransporteEntregaDelivery::class,
            'costo_envio' => 'decimal:2',
            'estado' => EstadoEntregaDelivery::class,
            'salida_at' => 'immutable_datetime',
            'entregada_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Pedido, $this> */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }
}
