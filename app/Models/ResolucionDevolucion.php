<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $devolucion_detalle_id
 * @property int $usuario_id
 * @property string $usuario_nombre
 * @property numeric-string $cantidad
 * @property string $accion
 * @property string $motivo
 * @property string $clave_idempotencia
 * @property CarbonImmutable $resuelta_at
 */
class ResolucionDevolucion extends Model
{
    protected $table = 'resoluciones_devolucion';

    protected $fillable = ['devolucion_detalle_id', 'usuario_id', 'usuario_nombre', 'cantidad', 'accion', 'motivo', 'clave_idempotencia', 'resuelta_at'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['cantidad' => 'decimal:4', 'resuelta_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DevolucionDetalle,$this> */
    public function detalleDevolucion(): BelongsTo
    {
        return $this->belongsTo(DevolucionDetalle::class, 'devolucion_detalle_id');
    }

    /** @return HasMany<ResolucionDevolucionDetalle,$this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(ResolucionDevolucionDetalle::class, 'resolucion_devolucion_id');
    }
}
