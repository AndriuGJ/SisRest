<?php

namespace App\Models;

use App\Enums\EstadoCobro;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string|null $grupo_pagador
 * @property string|null $cliente_nombre
 * @property string|null $cliente_tipo_documento
 * @property string|null $cliente_numero_documento
 * @property string|null $cliente_direccion
 * @property string|null $caja_nombre
 * @property string|null $cajero_nombre
 * @property string|null $beneficio_motivo_nombre
 * @property string|null $beneficio_motivo_texto
 * @property string|null $beneficio_autorizado_nombre
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $codigo
 * @property int $pedido_id
 * @property int|null $cliente_id
 * @property int $turno_caja_id
 * @property int $usuario_id
 * @property EstadoCobro $estado
 * @property string $moneda
 * @property numeric-string $subtotal
 * @property numeric-string $descuento_productos
 * @property string|null $descuento_tipo
 * @property numeric-string $descuento_valor
 * @property numeric-string $descuento_general
 * @property int|null $motivo_beneficio_id
 * @property int|null $autorizado_por
 * @property numeric-string $cargo_envio
 * @property numeric-string $impuestos
 * @property numeric-string $total
 * @property CarbonImmutable|null $cobrado_at
 * @property string $clave_idempotencia
 */
class Cobro extends Model
{
    protected $table = 'cobros';

    protected $fillable = [
        'beneficio_motivo_nombre', 'beneficio_motivo_texto', 'beneficio_autorizado_nombre',
        'grupo_pagador', 'cliente_nombre', 'cliente_tipo_documento', 'cliente_numero_documento', 'cliente_direccion', 'caja_nombre', 'cajero_nombre',
        'codigo',
        'pedido_id',
        'cliente_id',
        'turno_caja_id',
        'usuario_id',
        'estado',
        'moneda',
        'subtotal',
        'descuento_productos',
        'descuento_tipo',
        'descuento_valor',
        'descuento_general',
        'motivo_beneficio_id',
        'autorizado_por',
        'cargo_envio',
        'impuestos',
        'total',
        'cobrado_at',
        'clave_idempotencia',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'estado' => EstadoCobro::class,
            'subtotal' => 'decimal:2',
            'descuento_productos' => 'decimal:2',
            'descuento_valor' => 'decimal:4',
            'descuento_general' => 'decimal:2',
            'cargo_envio' => 'decimal:2',
            'impuestos' => 'decimal:2',
            'total' => 'decimal:2',
            'cobrado_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Pedido, $this> */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
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

    /** @return HasMany<CobroDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(CobroDetalle::class, 'cobro_id');
    }

    /** @return HasMany<Pago, $this> */
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'cobro_id');
    }

    /** @return HasOne<Comprobante, $this> */
    public function comprobante(): HasOne
    {
        return $this->hasOne(Comprobante::class, 'cobro_id');
    }
}
