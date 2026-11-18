<?php

namespace App\Models;

use App\Enums\EstadoPedido;
use App\Enums\ModalidadPedido;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $codigo
 * @property ModalidadPedido $modalidad
 * @property int|null $mesa_id
 * @property int|null $mozo_id
 * @property int|null $cliente_id
 * @property int $creado_por
 * @property EstadoPedido $estado
 * @property int|null $comensales
 * @property string|null $observaciones
 * @property CarbonImmutable $abierto_at
 * @property CarbonImmutable|null $cerrado_at
 */
class Pedido extends Model
{
    protected $table = 'pedidos';

    protected $fillable = [
        'codigo',
        'modalidad',
        'mesa_id',
        'mozo_id',
        'cliente_id',
        'creado_por',
        'estado',
        'comensales',
        'observaciones',
        'abierto_at',
        'cerrado_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'modalidad' => ModalidadPedido::class,
            'estado' => EstadoPedido::class,
            'abierto_at' => 'immutable_datetime',
            'cerrado_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Mesa, $this> */
    public function mesa(): BelongsTo
    {
        return $this->belongsTo(Mesa::class, 'mesa_id');
    }

    /** @return BelongsTo<User, $this> */
    public function mozo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mozo_id');
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    /** @return HasMany<PedidoDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(PedidoDetalle::class, 'pedido_id');
    }

    /** @return HasMany<Cobro, $this> */
    public function cobros(): HasMany
    {
        return $this->hasMany(Cobro::class, 'pedido_id');
    }

    /** @return HasOne<EntregaDelivery, $this> */
    public function delivery(): HasOne
    {
        return $this->hasOne(EntregaDelivery::class, 'pedido_id');
    }
}
