<?php

namespace App\Models;

use App\Enums\EstadoComprobanteEnvio;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int $comprobante_id
 * @property int $intento
 * @property EstadoComprobanteEnvio $estado
 * @property string|null $ticket
 * @property string|null $codigo_respuesta
 * @property string|null $mensaje
 * @property string|null $cdr_ruta
 * @property CarbonImmutable|null $iniciado_at
 * @property CarbonImmutable|null $finalizado_at
 */
class ComprobanteEnvio extends Model
{
    protected $table = 'comprobante_envios';

    protected $fillable = [
        'comprobante_id',
        'intento',
        'estado',
        'ticket',
        'codigo_respuesta',
        'mensaje',
        'cdr_ruta',
        'iniciado_at',
        'finalizado_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'estado' => EstadoComprobanteEnvio::class,
            'iniciado_at' => 'immutable_datetime',
            'finalizado_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Comprobante, $this> */
    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class, 'comprobante_id');
    }
}
